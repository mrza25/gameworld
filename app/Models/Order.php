<?php

namespace App\Models;

use App\Helpers\Jalali;
use App\OrderStatus;
use App\PaymentMethod;
use App\PaymentStatus;
use App\Services\CardPaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
        'payment_due_at',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'payment_status' => PaymentStatus::class,
        'total' => 'decimal:2',
        'payment_due_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // public function console(): BelongsTo
    // {
    //     return $this->belongsTo(Console::class);
    // }

    /**
     * Turn an order reference as shown to users ("123", "#123", "ORD-123") into its id.
     */
    public static function idFromReference(int|string $reference): int
    {
        return (int) preg_replace('/^(#|ORD-)/i', '', trim((string) $reference));
    }

    /**
     * Orders placed for the given console slug(s), comma-separated. Orders store
     * the console name, so slugs are matched through the platforms table.
     */
    public function scopeForConsole(Builder $query, string $consoleSlugs): Builder
    {
        $slugs = array_filter(array_map('trim', explode(',', $consoleSlugs)));
        $names = Platform::whereIn('slug', $slugs)->pluck('name')->all();

        return $query->whereIn('console_type', array_merge($names, $slugs));
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Whether the order is waiting for its card-to-card payment to be confirmed.
     * Nothing else may happen to it until then.
     */
    public function isAwaitingPayment(): bool
    {
        return $this->status === OrderStatus::AwaitingPayment;
    }

    public function latestCardPayment(): ?Payment
    {
        return $this->payments
            ->filter(fn (Payment $payment) => $payment->method === PaymentMethod::CardToCard)
            ->sortByDesc('id')
            ->first();
    }

    /**
     * Whether a customer may still cancel this order: nothing has started
     * installing yet, and no receipt is under review, since that money may
     * already have been transferred.
     */
    public function isCancellableByCustomer(): bool
    {
        if ($this->isAwaitingPayment()) {
            return ! $this->latestCardPayment()?->hasReceiptInReview();
        }

        return $this->status === OrderStatus::Pending
            && $this->items->every(fn ($item) => $item->installation_status === 'pending');
    }

    /**
     * Orders with a receipt waiting for an admin.
     */
    public function scopeWithReceiptInReview(Builder $query): Builder
    {
        return $query->whereHas('payments', fn ($payments) => $payments->inReview());
    }

    /**
     * Every receipt sent for this order, newest first, for the admin who reviews
     * them, along with anywhere else the same file was used.
     *
     * @return list<array<string, mixed>>
     */
    public function receiptHistoryArray(): array
    {
        $receipts = $this->payments
            ->filter(fn (Payment $payment) => $payment->method === PaymentMethod::CardToCard && $payment->receipt_path)
            ->sortByDesc('id');

        $duplicates = Payment::duplicateReceipts($receipts);

        return $receipts
            ->map(fn (Payment $payment) => [
                'payment_id' => $payment->id,
                'status' => $payment->reviewStatus(),
                'amount' => (float) $payment->amount,
                'receipt_url' => $payment->receiptUrl(),
                'uploaded_at' => $payment->receipt_uploaded_at?->toIso8601String(),
                'reviewed_at' => $payment->reviewed_at?->toIso8601String(),
                'reviewed_by' => $payment->reviewer ? ['id' => $payment->reviewer->id, 'name' => $payment->reviewer->name] : null,
                'reject_reason' => $payment->review_note,
            ] + $duplicates[$payment->id])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cardPaymentArray(): ?array
    {
        $payment = $this->latestCardPayment();

        if ($payment === null) {
            return null;
        }

        $isAwaitingPayment = $this->isAwaitingPayment();
        $reviewStatus = $payment->reviewStatus();

        return [
            'status' => $reviewStatus,
            'amount' => (float) $payment->amount,
            'card' => $isAwaitingPayment ? CardPaymentService::destinationCard() : null,
            'due_at' => $isAwaitingPayment ? $this->payment_due_at?->toIso8601String() : null,
            'can_upload_receipt' => $isAwaitingPayment && $reviewStatus !== Payment::REVIEW_IN_REVIEW,
            'receipt_url' => $payment->receiptUrl(),
            'receipt_uploaded_at' => $payment->receipt_uploaded_at?->toIso8601String(),
            'reviewed_at' => $payment->reviewed_at?->toIso8601String(),
            'reject_reason' => $reviewStatus === Payment::REVIEW_REJECTED ? $payment->review_note : null,
        ];
    }

    public function syncStatusFromItems(): void
    {
        if ($this->status === OrderStatus::Cancelled || $this->isAwaitingPayment()) {
            return;
        }

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

    public function toApiArray(): array
    {
        $statusMap = [
            'awaiting_payment' => 'در انتظار تأیید پرداخت',
            'pending' => 'در حال بررسی',
            'processing' => 'در حال پردازش',
            'completed' => 'تکمیل شده',
            'cancelled' => 'لغو شده',
        ];

        $paymentMap = [
            'unpaid' => 'در انتظار پرداخت',
            'partial' => 'پرداخت ناقص',
            'paid' => 'پرداخت شده',
            'failed' => 'ناموفق',
            'refunded' => 'مسترد شده',
            'pending' => 'در انتظار پرداخت',
        ];

        $installMap = [
            'pending' => 'در انتظار نصب',
            'installing' => 'در حال نصب',
            'installed' => 'نصب شده',
        ];

        $statusValue = $this->status instanceof \BackedEnum ? $this->status->value : $this->status;
        $paymentValue = $this->payment_status instanceof \BackedEnum ? $this->payment_status->value : $this->payment_status;

        $items = $this->relationLoaded('items') ? $this->items : $this->items()->with(['game', 'gameVariant.platform', 'selectedOption'])->get();

        $platform = Platform::findByNameOrSlug($this->console_type);
        $consoleSlug = $platform?->slug ?? Str::slug($this->console_type);

        $paymentMethod = ($this->payments->first(fn (Payment $payment) => $payment->status === PaymentStatus::Paid)
            ?? $this->latestCardPayment())?->method?->value;

        return [
            'id' => $this->id,
            'orderId' => '#'.$this->id,
            'paymentMethod' => $paymentMethod,
            'card_payment' => $this->cardPaymentArray(),
            'cancellable' => $this->isCancellableByCustomer(),
            'userId' => $this->user_id,
            'name' => $this->user?->name,
            'phone' => $this->user?->phonenumber,
            'console' => $consoleSlug,
            'consoleLabel' => $this->console_type,
            'consoleNote' => $this->console_note,
            'amount' => (float) $this->total,
            'total' => (float) $this->total,
            'status' => $statusMap[$statusValue] ?? $statusValue,
            'statusRaw' => $statusValue,
            'paymentStatus' => $paymentMap[$paymentValue] ?? $paymentValue,
            'paymentStatusRaw' => $paymentValue,
            'date' => Jalali::fromDateTime($this->created_at),
            'time' => $this->created_at?->format('H:i'),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'productId' => $item->game?->slug ?? (string) $item->game_id,
                'gameId' => $item->game_id,
                'variantId' => $item->game_variant_id,
                'variant_id' => $item->game_variant_id,
                'game' => $item->game ? [
                    'id' => $item->game->id,
                    'title' => $item->game->title,
                    'slug' => $item->game->slug,
                    'image' => $item->game->coverUrl(),
                ] : null,
                'variant' => $item->gameVariant ? [
                    'id' => $item->gameVariant->id,
                    'platform_id' => $item->gameVariant->platform?->slug,
                    'platform' => $item->gameVariant->platform?->slug,
                    'platform_info' => $item->gameVariant->platform?->toConsoleArray(),
                    'size' => (float) $item->gameVariant->size,
                ] : null,
                'title' => $item->game?->title,
                'genre' => $item->game?->genre,
                'price' => (float) $item->price,
                'size' => $item->gameVariant ? (float) $item->gameVariant->size : null,
                'image' => $item->game?->coverUrl(),
                'quantity' => $item->quantity,
                'installStatus' => $installMap[$item->installation_status] ?? $item->installation_status,
                'installStatusRaw' => $item->installation_status,
                'installed_at' => $item->installed_at?->format('Y-m-d H:i'),
                'selected_option' => $item->selectedOption ? [
                    'id' => $item->selectedOption->game_variant_option_id,
                    'label' => $item->selectedOption->label,
                    'price' => (float) $item->selectedOption->price,
                ] : null,
            ])->values()->all(),
        ];
    }
}
