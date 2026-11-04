<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameOption>
 */
class GameOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'label' => fake()->unique()->words(2, true),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
