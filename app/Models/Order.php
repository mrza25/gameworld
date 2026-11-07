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

    public const NUMBER_PREFIX = 'GW-';

    /**
     * Looks alike when read aloud or copied by hand, so left out of order numbers.
     */
    private const NUMBER_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const INSTALLATION_LABELS = [
        'pending' => 'در انتظار نصب',
        'installing' => 'در حال نصب',
        'installed' => 'نصب شده',
    ];

    protected $fillable = [
        'number',
        'user_id',
        'console_type',
        'console_note',
        'subtotal',
        'discount_amount',
        'coupon_code',
        'total',
        'status',
        'payment_status',
        'payment_due_at',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'payment_status' => PaymentStatus::class,
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'payment_due_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            $order->number ??= self::generateNumber();
        });

        static::created(function (self $order) {
            $order->recordHistory(OrderHistory::EVENT_CREATED, to: $order->status?->value);
        });

        static::updated(function (self $order) {
            if ($order->wasChanged('status')) {
                $order->recordHistory(OrderHistory::EVENT_STATUS, $order->getRawOriginal('status'), $order->status?->value);
            }

            if ($order->wasChanged('payment_status')) {
                $order->recordHistory(OrderHistory::EVENT_PAYMENT, $order->getRawOriginal('payment_status'), $order->payment_status?->value);
            }
        });
    }

    /**
     * A number to show customers, like GW-261107-8F3KP. It is random, so unlike
     * the id it cannot be guessed and does not reveal how many orders exist.
     */
    public static function generateNumber(): string
    {
        do {
            $suffix = '';

            for ($i = 0; $i < 5; $i++) {
                $suffix .= self::NUMBER_ALPHABET[random_int(0, strlen(self::NUMBER_ALPHABET) - 1)];
            }

            $number = self::NUMBER_PREFIX.now()->format('ymd').'-'.$suffix;
        } while (self::query()->where('number', $number)->exists());

        return $number;
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderHistory::class)->orderBy('id');
    }

    public function recordHistory(string $event, ?string $from = null, ?string $to = null, ?string $note = null): void
    {
        $this->histories()->create([
            'user_id' => auth()->id(),
            'event' => $event,
            'from_value' => $from,
            'to_value' => $to,
            'note' => $note,
        ]);
    }

    /**
     * Who did what is only for admins, so customers get the events without it.
     *
     * @return list<array<string, mixed>>
     */
    public function historyArray(bool $withActor = false): array
    {
        return $this->histories
            ->map(fn (OrderHistory $history) => $history->toApiArray($withActor))
            ->values()
            ->all();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // public function console(): BelongsTo
    // {
    //     return $this->belongsTo(Console::class);
    // }

    /**
     * Turn an order reference as shown to users ("123", "#123", "ORD-123",
     * "GW-261107-8F3KP") into its id. An unknown number gives 0, which finds nothing.
     */
    public static function idFromReference(int|string $reference): int
    {
        $reference = mb_strtoupper(trim((string) $reference));

        if (str_starts_with($reference, self::NUMBER_PREFIX)) {
            return (int) self::query()->where('number', $reference)->value('id');
        }

        return (int) preg_replace('/^(#|ORD-)/', '', $reference);
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
                'tracking_code' => $payment->tracking_code,
                'card_last4' => $payment->card_last4,
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
            'tracking_code' => $payment->tracking_code,
            'card_last4' => $payment->card_last4,
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
            'number' => $this->number,
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
            'subtotal' => (float) ($this->subtotal ?? $this->total),
            'discount_amount' => (float) $this->discount_amount,
            'coupon_code' => $this->coupon_code,
            'status' => $this->status?->label() ?? $statusValue,
            'statusRaw' => $statusValue,
            'paymentStatus' => $this->payment_status?->label() ?? $paymentValue,
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
                'title' => $item->title ?? $item->game?->title,
                'platform_name' => $item->platform_name ?? $item->gameVariant?->platform?->name,
                'genre' => $item->game?->genre,
                'price' => (float) $item->price,
                'size' => $item->gameVariant ? (float) $item->gameVariant->size : null,
                'image' => $item->game?->coverUrl(),
                'quantity' => $item->quantity,
                'installStatus' => self::INSTALLATION_LABELS[$item->installation_status] ?? $item->installation_status,
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
