<?php

namespace Database\Factories;

use App\Models\Console;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Console>
 */
class ConsoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement([
                'PS4',
                'PS5',
                'Xbox',
            ]),
            'status' => 'pending',
        ];
    }
}
