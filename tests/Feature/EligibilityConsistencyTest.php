<?php

namespace Tests\Feature;

use App\Models\EligibilityOverride;
use App\Models\EligibilityRule;
use App\Models\Participant;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use App\Services\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The per-participant evaluation and the set-based batch query are two
 * implementations of one rule. This pins them to the same answer.
 */
class EligibilityConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    private array $forms = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create(['is_active' => true]);
        $this->webinar = Webinar::query()->create([
            'title' => 'Consistency', 'slug' => 'consistency', 'status' => 'published',
            'timezone' => 'UTC', 'created_by' => $this->administrator->id,
        ]);

        foreach (['registration', 'pretest', 'posttest', 'evaluation'] as $type) {
            $this->forms[$type] = $this->webinar->forms()->create([
                'type' => $type, 'title' => ucfirst($type), 'status' => 'published',
            ]);
        }
    }

    /** @return array<string, mixed> */
    public static function ruleSets(): array
    {
        return [
            'no requirements' => [[]],
            'registration only' => [[['registration', null]]],
            'registration and posttest' => [[['registration', null], ['posttest', null]]],
            'scored posttest' => [[['posttest', 5.0]]],
            'every stage scored' => [[['registration', null], ['pretest', 3.0], ['posttest', 8.0], ['evaluation', null]]],
        ];
    }

    /** @param  array<int, array{0: string, 1: float|null}>  $rules */
    #[DataProvider('ruleSets')]
    public function test_the_batch_query_agrees_with_per_participant_evaluation(array $rules): void
    {
        foreach ($rules as [$requirement, $minimum]) {
            EligibilityRule::query()->create([
                'webinar_id' => $this->webinar->id,
                'requirement' => $requirement,
                'is_required' => true,
                'minimum_score' => $minimum,
            ]);
        }

        $this->buildPopulation();

        $service = app(EligibilityService::class);

        $expected = $this->webinar->participants()->get()
            ->filter(fn (Participant $p) => $service->evaluate($p->fresh())['eligible'])
            ->pluck('id')->sort()->values()->all();

        $actual = $service->eligibleParticipantsQuery($this->webinar->fresh())
            ->pluck('participants.id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame($expected, $actual);
    }

    /** A spread of states: unverified, partial, scored, overridden, and expired overrides. */
    private function buildPopulation(): void
    {
        $make = function (string $email, bool $verified, array $scores, ?string $override = null, bool $expired = false) {
            $participant = Participant::query()->create([
                'webinar_id' => $this->webinar->id, 'full_name' => $email, 'email' => $email,
                'verified_at' => $verified ? now() : null,
            ]);

            foreach ($scores as $type => $score) {
                Submission::query()->create([
                    'form_id' => $this->forms[$type]->id,
                    'participant_id' => $participant->id,
                    'status' => 'submitted',
                    'score' => $score,
                    'maximum_score' => $score === null ? null : 10,
                    'submitted_at' => now(),
                ]);
            }

            if ($override) {
                EligibilityOverride::query()->create([
                    'participant_id' => $participant->id,
                    'decision' => $override,
                    'reason' => 'Recorded during a consistency test.',
                    'overridden_by' => $this->administrator->id,
                    'expires_at' => $expired ? now()->subDay() : null,
                ]);
            }

            return $participant;
        };

        $make('nothing@example.com', false, []);
        $make('registered-only@example.com', true, ['registration' => null]);
        $make('low-scores@example.com', true, ['registration' => null, 'pretest' => 1, 'posttest' => 2]);
        $make('high-scores@example.com', true, ['registration' => null, 'pretest' => 9, 'posttest' => 9, 'evaluation' => null]);
        $make('exact-threshold@example.com', true, ['registration' => null, 'pretest' => 3, 'posttest' => 8, 'evaluation' => null]);
        $make('unverified-but-scored@example.com', false, ['posttest' => 10]);
        $make('forced-in@example.com', false, [], 'eligible');
        $make('forced-out@example.com', true, ['registration' => null, 'pretest' => 10, 'posttest' => 10, 'evaluation' => null], 'ineligible');
        $make('expired-override@example.com', true, ['registration' => null], 'ineligible', expired: true);
        $make('null-score@example.com', true, ['registration' => null, 'posttest' => null]);
    }

    public function test_the_latest_override_wins_in_both_paths(): void
    {
        EligibilityRule::query()->create([
            'webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true,
        ]);

        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'email' => 'flip@example.com', 'verified_at' => now(),
        ]);

        foreach ([['ineligible', '-2 hours'], ['eligible', '-1 hour']] as [$decision, $when]) {
            EligibilityOverride::query()->create([
                'participant_id' => $participant->id, 'decision' => $decision,
                'reason' => 'Sequenced override for the test.', 'overridden_by' => $this->administrator->id,
                'created_at' => now()->modify($when),
            ]);
        }

        $service = app(EligibilityService::class);

        $this->assertTrue($service->evaluate($participant->fresh())['eligible']);
        $this->assertSame(
            [$participant->id],
            $service->eligibleParticipantsQuery($this->webinar->fresh())
                ->pluck('participants.id')->map(fn ($id) => (int) $id)->all(),
        );
    }
}
