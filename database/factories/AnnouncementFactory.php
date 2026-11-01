<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_id' => User::factory()->admin(),
            'title' => fake()->sentence(4),
            'message' => fake()->paragraph(),
            'link' => null,
            'audience' => Announcement::AUDIENCE_ALL,
            'recipients_count' => 0,
        ];
    }
}
