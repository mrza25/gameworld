<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'genre' => fake()->randomElement([
                'Action',
                'RPG',
                'Adventure',
                'Sports',
                'Racing',
                'Horror',
            ]),
            'platform' => fake()->randomElement([
                'PS4',
                'PS5',
                'Xbox',
            ]),
            'size' => fake()->randomFloat(2, 10, 150),
        ];
    }
}
