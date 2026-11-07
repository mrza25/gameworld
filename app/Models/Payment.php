<?php

namespace App\Models;

use App\PaymentMethod;
use App\PaymentStatus;
use App\Services\CardPaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

class Payment extends Model
{
    use HasFactory;

    public const REVIEW_AWAITING_RECEIPT = 'awaiting_receipt';

    public const REVIEW_IN_REVIEW = 'in_review';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    public const REVIEW_REFUNDED = 'refunded';

    public const REVIEW_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'order_id',
        'amount',
        'method',
        'status',
        'transaction_id',
        'paid_at',
        'metadata',
        'receipt_path',
        'receipt_hash',
        'receipt_uploaded_at',
        'tracking_code',
        'card_last4',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $hidden = [
        'receipt_path',
        'receipt_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'method' => PaymentMethod::class,
        'status' => PaymentStatus::class,
        'paid_at' => 'datetime',
        'metadata' => 'array',
        'receipt_uploaded_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Whether the given gateway transaction id belongs to this payment.
     */
    public function matchesTransaction(string $transactionId): bool
    {
        return is_string($this->transaction_id) && hash_equals($this->transaction_id, $transactionId);
    }

    /**
     * Whether this payment tops up the wallet rather than paying for games.
     */
    public function isWalletCharge(): bool
    {
        return $this->order_id === null && empty($this->metadata['items']);
    }

    /**
     * Card-to-card payments whose receipt is waiting for an admin.
     */
    public function scopeInReview(Builder $query): Builder
    {
        return $query
            ->where('method', PaymentMethod::CardToCard)
            ->where('status', PaymentStatus::Pending)
            ->whereNotNull('receipt_path');
    }

    /**
     * Wallet top-ups paid by card-to-card.
     */
    public function scopeCardWalletCharges(Builder $query): Builder
    {
        return $query
            ->where('method', PaymentMethod::CardToCard)
            ->whereNull('order_id');
    }

    /**
     * Where else each payment's receipt was used, keyed by payment id. The same
     * file or bank tracking code on another order or top-up usually means one
     * transfer is being claimed twice. Earlier attempts on the same order don't count.
     *
     * @param  Collection<int, Payment>  $payments
     * @return array<int, array{duplicate_order_ids: list<int>, duplicate_wallet_charge_ids: list<int>}>
     */
    public static function duplicateReceipts(Collection $payments): array
    {
        $candidates = self::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('receipt_hash', $payments->pluck('receipt_hash')->filter()->all())
                ->orWhereIn('tracking_code', $payments->pluck('tracking_code')->filter()->all()))
            ->get(['id', 'order_id', 'receipt_hash', 'tracking_code']);

        return $payments
            ->mapWithKeys(function (Payment $payment) use ($candidates) {
                $others = $candidates->filter(
                    fn (Payment $other) => $other->id !== $payment->id
                        && ($payment->order_id === null || $other->order_id !== $payment->order_id)
                        && (
                            ($payment->receipt_hash && $other->receipt_hash === $payment->receipt_hash)
                            || ($payment->tracking_code && $other->tracking_code === $payment->tracking_code)
                        )
                );

                return [$payment->id => [
                    'duplicate_order_ids' => $others->pluck('order_id')->filter()->unique()->values()->all(),
                    'duplicate_wallet_charge_ids' => $others->whereNull('order_id')->pluck('id')->values()->all(),
                ]];
            })
            ->all();
    }

    /**
     * A card-to-card wallet top-up as the API shows it.
     *
     * @return array<string, mixed>
     */
    public function toWalletChargeArray(): array
    {
        $reviewStatus = $this->reviewStatus();
        $isWaitingForReceipt = $reviewStatus === self::REVIEW_AWAITING_RECEIPT;

        return [
            'id' => $this->id,
            'method' => PaymentMethod::CardToCard->value,
            'status' => $reviewStatus,
            'amount' => (float) $this->amount,
            'card' => $this->status === PaymentStatus::Pending ? CardPaymentService::destinationCard() : null,
            'due_at' => $isWaitingForReceipt ? $this->created_at?->copy()->addHours(CardPaymentService::paymentHours())->toIso8601String() : null,
            'can_upload_receipt' => $isWaitingForReceipt,
            'receipt_url' => $this->receiptUrl(),
            'receipt_uploaded_at' => $this->receipt_uploaded_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reject_reason' => $reviewStatus === self::REVIEW_REJECTED ? $this->review_note : null,
            'tracking_code' => $this->tracking_code,
            'card_last4' => $this->card_last4,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function hasReceiptInReview(): bool
    {
        return $this->reviewStatus() === self::REVIEW_IN_REVIEW;
    }

    /**
     * Where a card-to-card payment stands, in the terms the customer sees.
     */
    public function reviewStatus(): string
    {
        return match ($this->status) {
            PaymentStatus::Paid => self::REVIEW_APPROVED,
            PaymentStatus::Refunded => self::REVIEW_REFUNDED,
            PaymentStatus::Pending => $this->receipt_path ? self::REVIEW_IN_REVIEW : self::REVIEW_AWAITING_RECEIPT,
            default => $this->reviewed_at ? self::REVIEW_REJECTED : self::REVIEW_CANCELLED,
        };
    }

    /**
     * A short-lived link to the receipt. Receipts are private, and an image tag
     * cannot send a token, so the link itself carries the permission.
     */
    public function receiptUrl(): ?string
    {
        if (! $this->receipt_path) {
            return null;
        }

        return url(URL::temporarySignedRoute(
            'api.receipts.show',
            now()->addMinutes(30),
            ['payment' => $this->id],
            absolute: false
        ));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
