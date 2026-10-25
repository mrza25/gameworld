<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
        $title = fake()->sentence(3);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->randomNumber(4),
            'genre' => fake()->randomElement([
                'Action',
                'RPG',
                'Adventure',
                'Sports',
                'Racing',
                'Horror',
            ]),
            'description' => fake()->paragraph(),
            'developer' => fake()->company(),
            'platform' => fake()->randomElement([
                'PS4',
                'PS5',
                'Xbox',
            ]),
            'size' => fake()->randomFloat(2, 10, 150),
            'is_available' => true,
        ];
    }
}
