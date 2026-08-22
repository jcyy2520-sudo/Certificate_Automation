<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShareLinkAndExportTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->administrator)->post(route('admin.webinars.store'), [
            'title' => 'Cyber Hygiene Clinic', 'status' => 'published',
            'timezone' => 'UTC', 'data_retention_days' => 7, 'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'requires_verification' => '0',
        ]);
        $this->webinar = Webinar::query()->where('slug', 'cyber-hygiene-clinic')->firstOrFail();
    }

    public function test_every_new_form_gets_a_distinct_unguessable_share_token(): void
    {
        $forms = $this->webinar->forms;
        $tokens = $forms->pluck('public_token');

        $this->assertCount(4, $tokens);
        $this->assertCount(4, $tokens->unique(), 'Share tokens must not collide.');

        foreach ($tokens as $token) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]{24}$/', $token);
        }

        $rawTokens = DB::table('forms')->where('webinar_id', $this->webinar->id)->pluck('public_token', 'id');
        foreach ($forms as $form) {
            $this->assertNotSame($form->public_token, $rawTokens[$form->id], 'Share tokens must be encrypted at rest.');
            $this->assertSame(Form::publicTokenHash($form->public_token), $form->public_token_hash);
        }
    }

    public function test_the_form_editor_shows_the_share_link(): void
    {
        $form = $this->webinar->forms->firstWhere('type', 'registration');

        $this->actingAs($this->administrator)
            ->get(route('admin.forms.edit', [$this->webinar, $form]))
            ->assertOk()
            ->assertSee($form->shareUrl())
            ->assertSee('opens this form and nothing else', false);
    }

    public function test_rotating_the_link_retires_the_old_url(): void
    {
        $form = $this->webinar->forms->firstWhere('type', 'registration');
        $original = $form->public_token;

        $this->actingAs($this->administrator)
            ->post(route('admin.forms.rotate-link', [$this->webinar, $form]))
            ->assertRedirect()->assertSessionHas('success');

        $form->refresh();
        $this->assertNotSame($original, $form->public_token);
        $this->assertNotNull($form->link_rotated_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.link_rotated']);

        $this->get('/f/'.$original)->assertNotFound();
        $this->get($form->shareUrl())->assertOk();
    }

    public function test_the_completion_list_counts_and_filters_by_requirements(): void
    {
        $registration = $this->webinar->forms->firstWhere('type', 'registration');

        $this->post($registration->shareUrl(), ['full_name' => 'Done Dana', 'email' => 'dana@example.com', 'privacy_acknowledged' => '1']);
        Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Missing Marco', 'email' => 'marco@example.com',
        ]);

        $this->actingAs($this->administrator)
            ->get(route('admin.participants.index', $this->webinar))
            ->assertOk()
            ->assertSee('Done Dana')
            ->assertSee('Missing Marco')
            ->assertSee('met every requirement', false);

        $this->actingAs($this->administrator)
            ->post(route('admin.participants.filter', $this->webinar), ['filter' => 'complete'])
            ->assertRedirect(route('admin.participants.index', $this->webinar));
        $this->get(route('admin.participants.index', $this->webinar))
            ->assertOk()->assertSee('Done Dana')->assertDontSee('Missing Marco');

        $this->post(route('admin.participants.filter', $this->webinar), ['filter' => 'incomplete'])
            ->assertRedirect(route('admin.participants.index', $this->webinar));
        $this->get(route('admin.participants.index', $this->webinar))
            ->assertOk()->assertSee('Missing Marco')->assertDontSee('Done Dana');
    }

    public function test_the_csv_export_lists_who_met_every_requirement(): void
    {
        $registration = $this->webinar->forms->firstWhere('type', 'registration');
        $this->post($registration->shareUrl(), [
            'full_name' => 'Done Dana', 'email' => 'dana@example.com',
            'organization' => 'City Health', 'privacy_acknowledged' => '1',
        ]);
        Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Missing Marco', 'email' => 'marco@example.com',
        ]);

        $response = $this->actingAs($this->administrator)->get(route('admin.participants.export', $this->webinar));
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $lines = preg_split('/\R/', trim($response->streamedContent()));
        $rows = array_map('str_getcsv', array_filter($lines));
        $header = array_shift($rows);
        $byName = collect($rows)->mapWithKeys(fn ($row) => [$row[0] => array_combine($header, $row)]);

        $this->assertContains('Meets all requirements', $header);
        $this->assertSame('dana@example.com', $byName['Done Dana']['Email']);
        $this->assertSame('City Health', $byName['Done Dana']['Organization']);
        $this->assertSame('Yes', $byName['Done Dana']['Registration']);
        $this->assertSame('Yes', $byName['Done Dana']['Meets all requirements']);

        $this->assertSame('No', $byName['Missing Marco']['Registration']);
        $this->assertSame('No', $byName['Missing Marco']['Meets all requirements']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'participants.exported']);
    }

    public function test_the_csv_export_neutralizes_spreadsheet_formulas_and_is_not_cacheable(): void
    {
        $registration = $this->webinar->forms->firstWhere('type', 'registration');
        $registration->update(['title' => '+FORMULA HEADER']);
        Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'full_name' => '=HYPERLINK("https://attacker.invalid","open")',
            'email' => 'safe@example.com',
            'organization' => " \t@SUM(1+1)",
        ]);

        $response = $this->actingAs($this->administrator)->get(route('admin.participants.export', $this->webinar));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $rows = array_map('str_getcsv', array_filter(preg_split('/\R/', trim($response->streamedContent()))));
        $this->assertContains("'+FORMULA HEADER", $rows[0]);
        $this->assertSame("'=HYPERLINK(\"https://attacker.invalid\",\"open\")", $rows[1][0]);
        $this->assertSame("' \t@SUM(1+1)", $rows[1][2]);
    }
}
