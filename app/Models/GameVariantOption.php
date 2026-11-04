<?php

namespace App\Models;

use Database\Factories\GameVariantOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price row of a game on one console, e.g. "ظرفیت ۱ = 1,000,000". The customer
 * buys exactly one, and its price is the item's whole price. Removed rows are
 * deactivated, so payments in progress and past orders keep pointing at them.
 */
class GameVariantOption extends Model
{
    /** @use HasFactory<GameVariantOptionFactory> */
    use HasFactory;

    protected $fillable = [
        'game_variant_id',
        'label',
        'price',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function gameVariant(): BelongsTo
    {
        return $this->belongsTo(GameVariant::class);
    }
}
