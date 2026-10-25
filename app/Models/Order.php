<?php

namespace App\Models;

use App\Helpers\Jalali;
use App\OrderStatus;
use App\PaymentStatus;
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

    public function toApiArray(): array
    {
        $statusMap = [
            'pending' => 'در حال بررسی',
            'processing' => 'در حال پردازش',
            'completed' => 'تکمیل شده',
        ];

        $paymentMap = [
            'unpaid' => 'در انتظار پرداخت',
            'partial' => 'پرداخت ناقص',
            'paid' => 'پرداخت شده',
            'failed' => 'ناموفق',
        ];

        $installMap = [
            'pending' => 'در انتظار نصب',
            'installing' => 'در حال نصب',
            'installed' => 'نصب شده',
        ];

        $statusValue = $this->status instanceof \BackedEnum ? $this->status->value : $this->status;
        $paymentValue = $this->payment_status instanceof \BackedEnum ? $this->payment_status->value : $this->payment_status;

        $items = $this->relationLoaded('items') ? $this->items : $this->items()->with(['game', 'gameVariant.platform'])->get();

        $platform = Platform::findByNameOrSlug($this->console_type);
        $consoleSlug = $platform?->slug ?? Str::slug($this->console_type);

        return [
            'id' => $this->id,
            'orderId' => '#'.$this->id,
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
                'title' => $item->game?->title,
                'genre' => $item->game?->genre,
                'price' => (float) $item->price,
                'quantity' => $item->quantity,
                'installStatus' => $installMap[$item->installation_status] ?? $item->installation_status,
                'installStatusRaw' => $item->installation_status,
                'installed_at' => $item->installed_at?->format('Y-m-d H:i'),
            ])->values()->all(),
        ];
    }
}
