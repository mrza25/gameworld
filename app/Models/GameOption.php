<?php

namespace App\Models;

use Database\Factories\GameOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A free-text choice the admin offers for a game (e.g. "ظرفیت ۱"), which the
 * customer can tick when ordering it.
 */
class GameOption extends Model
{
    /** @use HasFactory<GameOptionFactory> */
    use HasFactory;

    protected $fillable = [
        'game_id',
        'label',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
