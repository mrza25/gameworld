<?php

namespace App\Models;

use App\ConsoleStatus;
use Database\Factories\ConsoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Console extends Model
{
    /** @use HasFactory<ConsoleFactory> */
    use HasFactory;

    protected $casts = [
        'status' => ConsoleStatus::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class)
            ->using(ConsoleGame::class)
            ->withPivot('status', 'installed_at')
            ->withTimestamps();
    }

    protected $fillable = [
        'user_id',
        'type',
        'status',
    ];
}
