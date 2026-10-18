<?php

namespace App\Models;

use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;




class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'console_type',
        'console_note',
        'total',
        'status',
        'payment_status',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'payment_status' => PaymentStatus::class,
        'total' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // public function console(): BelongsTo
    // {
    //     return $this->belongsTo(Console::class);
    // }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function syncStatusFromItems(): void
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $newStatus = OrderStatus::Pending;

        // All games are installed
        if ($items->every(fn ($item) => $item->installation_status === 'installed')) {
            $newStatus = OrderStatus::Completed;
        } elseif ($items->contains(fn ($item) => in_array($item->installation_status, ['installing', 'installed'], true))) {
            $newStatus = OrderStatus::Processing;
        }

        if ($this->status !== $newStatus) {
            $this->update(['status' => $newStatus]);
        }
    }
public function payments(): HasMany
{
    return $this->hasMany(Payment::class);
}
}
