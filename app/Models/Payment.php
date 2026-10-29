<?php

namespace App\Models;

use App\PaymentMethod;
use App\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'amount',
        'method',
        'status',
        'transaction_id',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'method' => PaymentMethod::class,
        'status' => PaymentStatus::class,
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Whether the given gateway transaction id belongs to this payment.
     */
    public function matchesTransaction(string $transactionId): bool
    {
        return is_string($this->transaction_id) && hash_equals($this->transaction_id, $transactionId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
