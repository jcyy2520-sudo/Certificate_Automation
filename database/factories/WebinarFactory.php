<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Webinar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A realistic, published webinar.
 *
 * The defaults mirror what an administrator produces through the admin flow:
 * a published event with a concrete schedule, so the model's privacy
 * lifecycle commits a `retention_due_at` on save (a published webinar with no
 * `ends_at` deliberately gets no deadline and keeps its forms unreachable).
 * Registration is left open (null window) so a registration form is live.
 *
 * @extends Factory<Webinar>
 */
class WebinarFactory extends Factory
{
    protected $model = Webinar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->catchPhrase();

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'status' => 'published',
            'timezone' => 'UTC',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'registration_opens_at' => null,
            'registration_closes_at' => null,
            'data_retention_days' => 7,
            'created_by' => User::factory(),
        ];
    }

    /** A draft event that has not committed a retention deadline. */
    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'draft']);
    }
}
