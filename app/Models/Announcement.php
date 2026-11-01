<?php

namespace App\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notification an admin sent to one, several or all users. Each recipient
 * gets an AdminAnnouncement notification; this row records what was sent.
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_USERS = 'users';

    protected $fillable = [
        'sender_id',
        'title',
        'message',
        'link',
        'audience',
        'recipients_count',
    ];

    protected function casts(): array
    {
        return [
            'recipients_count' => 'integer',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'link' => $this->link,
            'audience' => $this->audience,
            'recipients_count' => $this->recipients_count,
            'sender' => $this->sender ? ['id' => $this->sender->id, 'name' => $this->sender->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
