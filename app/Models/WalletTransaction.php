<?php

namespace App\Models;

use App\Helpers\Jalali;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WalletTransaction extends Model
{
    use HasFactory;

    /**
     * Transaction categories shown to customers, derived from type + reference.
     */
    public const CATEGORIES = ['deposit', 'payment', 'refund', 'adjustment', 'other'];

    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'balance_after',
        'reference',
        'idempotency_key',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // A payment or refund happens once, so the database refuses a second row for it.
        // Admin adjustments share one reference and can repeat, so they get no key.
        static::creating(function (self $transaction) {
            $reference = (string) $transaction->reference;

            if (Str::startsWith($reference, ['payment-', 'order-refund-'])) {
                $transaction->idempotency_key ??= $reference;
            }
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * deposit (gateway top-up), payment (order paid from wallet), refund
     * (cancelled order), adjustment (changed by an admin) or other.
     */
    public function category(): string
    {
        $reference = (string) $this->reference;

        return match (true) {
            Str::startsWith($reference, 'order-refund-') => 'refund',
            $reference === 'admin-adjustment' => 'adjustment',
            Str::startsWith($reference, 'payment-') => $this->type === 'credit' ? 'deposit' : 'payment',
            default => 'other',
        };
    }

    /**
     * Filter by type (credit | debit) or by category.
     */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return match ($kind) {
            'credit', 'debit' => $query->where('type', $kind),
            'deposit' => $query->where('type', 'credit')->where('reference', 'like', 'payment-%'),
            'payment' => $query->where('type', 'debit')->where('reference', 'like', 'payment-%'),
            'refund' => $query->where('reference', 'like', 'order-refund-%'),
            'adjustment' => $query->where('reference', 'admin-adjustment'),
            default => $query,
        };
    }

    /**
     * API shape for a list of transactions, resolving the related order ids in
     * one query.
     *
     * @param  Collection<int, WalletTransaction>  $transactions
     * @return Collection<int, array<string, mixed>>
     */
    public static function toApiCollection(Collection $transactions): Collection
    {
        $paymentIds = $transactions
            ->map(fn (self $transaction) => Str::startsWith((string) $transaction->reference, 'payment-')
                ? (int) Str::after($transaction->reference, 'payment-')
                : null)
            ->filter()
            ->unique()
            ->values();

        $orderIdsByPayment = $paymentIds->isEmpty()
            ? collect()
            : Payment::whereIn('id', $paymentIds)->pluck('order_id', 'id');

        return $transactions->map(function (self $transaction) use ($orderIdsByPayment) {
            $reference = (string) $transaction->reference;
            $orderId = match (true) {
                Str::startsWith($reference, 'order-refund-') => (int) Str::after($reference, 'order-refund-'),
                Str::startsWith($reference, 'payment-') => $orderIdsByPayment[(int) Str::after($reference, 'payment-')] ?? null,
                default => null,
            };

            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'category' => $transaction->category(),
                'amount' => (float) $transaction->amount,
                'balanceAfter' => (float) $transaction->balance_after,
                'balance_after' => (float) $transaction->balance_after,
                'reference' => $transaction->reference,
                'description' => $transaction->description,
                'order_id' => $orderId,
                'date' => Jalali::fromDateTime($transaction->created_at, 'Y/m/d H:i'),
                'created_at' => $transaction->created_at,
            ];
        })->values();
    }
}
