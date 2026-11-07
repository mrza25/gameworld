<?php

namespace App\Models;

use App\Helpers\Jalali;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to an order. Rows are only ever added.
 */
class OrderHistory extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_CREATED = 'created';

    public const EVENT_STATUS = 'status';

    public const EVENT_PAYMENT = 'payment';

    public const EVENT_INSTALLATION = 'installation';

    public const EVENT_RECEIPT_SUBMITTED = 'receipt_submitted';

    public const EVENT_RECEIPT_REJECTED = 'receipt_rejected';

    protected $fillable = [
        'order_id',
        'user_id',
        'event',
        'from_value',
        'to_value',
        'note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return match ($this->event) {
            self::EVENT_CREATED => 'سفارش ثبت شد',
            self::EVENT_STATUS => 'وضعیت سفارش: '.(OrderStatus::tryFrom((string) $this->to_value)?->label() ?? $this->to_value),
            self::EVENT_PAYMENT => 'وضعیت پرداخت: '.(PaymentStatus::tryFrom((string) $this->to_value)?->label() ?? $this->to_value),
            self::EVENT_INSTALLATION => 'نصب '.$this->note.': '.(Order::INSTALLATION_LABELS[$this->to_value] ?? $this->to_value),
            self::EVENT_RECEIPT_SUBMITTED => 'رسید پرداخت ارسال شد',
            self::EVENT_RECEIPT_REJECTED => 'رسید پرداخت رد شد',
            default => $this->event,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $withActor = false): array
    {
        $entry = [
            'event' => $this->event,
            'label' => $this->label(),
            'from' => $this->from_value,
            'to' => $this->to_value,
            'note' => $this->note,
            'date' => Jalali::fromDateTime($this->created_at, 'Y/m/d H:i'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($withActor) {
            $entry['actor'] = $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null;
        }

        return $entry;
    }
}
