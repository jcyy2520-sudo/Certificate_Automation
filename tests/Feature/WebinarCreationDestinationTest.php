<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebinarCreationDestinationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->owner = User::factory()->create(['role' => 'user']);
        $this->actingAs($this->owner);
    }

    public function test_upload_only_creation_opens_the_created_webinar_without_a_document(): void
    {
        $response = $this->post(route('admin.webinars.store'), [
            'title' => 'Synthetic document workspace',
            'creation_mode' => 'uploads',
        ]);

        $response->assertSessionHasNoErrors();
        $webinar = Webinar::query()->where('title', 'Synthetic document workspace')->firstOrFail();
        $response->assertRedirect(route('admin.webinars.show', $webinar));
        $response->assertSessionHas('success', 'Webinar created.');

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertViewHas('webinar', fn (Webinar $shown): bool => $shown->is($webinar))
            ->assertSee('Synthetic document workspace')
            ->assertSee('No files yet')
            ->assertSee('Add a document')
            ->assertSee('accept=".xlsx,.pdf,.docx,.png,.jpg,.jpeg"', false);

        $this->assertTrue($webinar->isUploadOnly());
        $this->assertSame('draft', $webinar->status);
        $this->assertSame($this->owner->id, $webinar->created_by);
        $this->assertCount(0, $webinar->documents);
        $this->assertCount(0, $webinar->forms);
        $this->assertCount(0, $webinar->eligibilityRules);
        $this->assertCount(0, $webinar->certificateTemplates);
    }

    #[DataProvider('standardCreationModes')]
    public function test_standard_creation_keeps_its_overview_destination_and_default_resources(?string $mode): void
    {
        $data = ['title' => 'Synthetic standard webinar', 'data_retention_days' => 30];
        if ($mode !== null) {
            $data['creation_mode'] = $mode;
        }

        $response = $this->post(route('admin.webinars.store'), $data);

        $response->assertSessionHasNoErrors();
        $webinar = Webinar::query()->where('title', 'Synthetic standard webinar')->firstOrFail();
        $response->assertRedirect(route('admin.webinars.show', $webinar));
        $this->get($response->headers->get('Location'))->assertOk()->assertViewIs('admin.webinars.show');

        $this->assertFalse($webinar->isUploadOnly());
        $this->assertCount(4, $webinar->forms);
        $this->assertCount(3, $webinar->eligibilityRules);
        $this->assertCount(1, $webinar->certificateTemplates);
    }

    public static function standardCreationModes(): array
    {
        return ['default mode' => [null], 'explicit standard mode' => ['standard']];
    }
}
