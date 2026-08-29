<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    /** @use HasFactory<\Database\Factories\GameFactory> */
    use HasFactory;

    public function consoles(): BelongsToMany
    {
        return $this->belongsToMany(
            Console::class,
            'console_game',
            'game_id',
            'console_id'
        )
            ->using(ConsoleGame::class)
            ->withPivot('status', 'installed_at')
            ->withTimestamps();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    protected $fillable = [
        'title',
        'genre',
        'platform',
        'size',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'size' => 'decimal:2',
    ];
}
