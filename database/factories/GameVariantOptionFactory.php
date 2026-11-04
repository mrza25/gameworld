<?php

namespace Database\Factories;

use App\Models\GameVariantOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameVariantOption>
 */
class GameVariantOptionFactory extends Factory
{
    /**
     * Define the model's default state. Create it through a variant, e.g.
     * `$variant->options()->save(GameVariantOption::factory()->make())`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->unique()->words(2, true),
            'price' => fake()->numberBetween(100, 5000) * 1000,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
