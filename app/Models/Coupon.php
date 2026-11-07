<?php

namespace App\Models;

use App\CouponType;
use App\Helpers\PersianText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'max_discount',
        'min_order_amount',
        'usage_limit',
        'per_user_limit',
        'starts_at',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'type' => CouponType::class,
        'value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'usage_limit' => 'integer',
        'per_user_limit' => 'integer',
        'used_count' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    /**
     * Codes are stored in one form, so "nowruz ۱۴" and "NOWRUZ14" are the same code.
     */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', '', PersianText::latinDigits($code)));
    }

    /**
     * Why this coupon cannot be used at all right now, or null if it can.
     */
    public function unavailableReason(): ?string
    {
        return match (true) {
            ! $this->is_active => 'این کد تخفیف غیرفعال شده است.',
            $this->starts_at?->isFuture() === true => 'زمان استفاده از این کد تخفیف هنوز نرسیده است.',
            $this->expires_at?->isPast() === true => 'این کد تخفیف منقضی شده است.',
            $this->usage_limit !== null && $this->used_count >= $this->usage_limit => 'ظرفیت استفاده از این کد تخفیف تمام شده است.',
            default => null,
        };
    }

    /**
     * The discount on an amount, in whole tomans and never more than the amount.
     */
    public function discountFor(float $amount): float
    {
        $discount = $this->type === CouponType::Percent
            ? floor($amount * (float) $this->value / 100)
            : (float) $this->value;

        if ($this->max_discount !== null) {
            $discount = min($discount, (float) $this->max_discount);
        }

        return min($discount, $amount);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'value' => (float) $this->value,
            'max_discount' => $this->max_discount === null ? null : (float) $this->max_discount,
            'min_order_amount' => (float) $this->min_order_amount,
            'usage_limit' => $this->usage_limit,
            'per_user_limit' => $this->per_user_limit,
            'used_count' => (int) $this->used_count,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_active' => (bool) $this->is_active,
            'is_usable' => $this->unavailableReason() === null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
