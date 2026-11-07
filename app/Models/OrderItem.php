<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'game_id',
        'game_variant_id',
        'title',
        'platform_name',
        'quantity',
        'price',
        'installation_status',
        'installed_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'installed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // What was bought is copied, so renaming or removing a game never changes an old order.
        static::creating(function (self $item) {
            $item->title ??= Game::query()->whereKey($item->game_id)->value('title');
            $item->platform_name ??= GameVariant::query()->with('platform')->find($item->game_variant_id)?->platform?->name;
        });

        static::updated(function (self $item) {
            if ($item->wasChanged('installation_status')) {
                $item->order()->first()?->recordHistory(
                    OrderHistory::EVENT_INSTALLATION,
                    $item->getRawOriginal('installation_status'),
                    $item->installation_status,
                    $item->title
                );
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function gameVariant(): BelongsTo
    {
        return $this->belongsTo(GameVariant::class);
    }

    public function selectedOption(): HasOne
    {
        return $this->hasOne(OrderItemOption::class);
    }
}
