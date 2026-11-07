<?php

namespace Database\Factories;

use App\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'GW'.fake()->unique()->numerify('#####'),
            'type' => CouponType::Percent,
            'value' => 10,
            'is_active' => true,
        ];
    }

    public function fixed(float $value): static
    {
        return $this->state(fn () => ['type' => CouponType::Fixed, 'value' => $value]);
    }
}
