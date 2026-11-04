<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An option the customer ticked for an order item. The label is copied at
 * order time, so editing or removing the option never changes past orders.
 */
class OrderItemOption extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_item_id',
        'game_option_id',
        'label',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function gameOption(): BelongsTo
    {
        return $this->belongsTo(GameOption::class);
    }
}
