<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The price row a customer chose for an order item. Its label and price are
 * copied at order time, so later edits never change past orders.
 */
class OrderItemOption extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_item_id',
        'game_variant_option_id',
        'label',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function gameVariantOption(): BelongsTo
    {
        return $this->belongsTo(GameVariantOption::class);
    }
}
