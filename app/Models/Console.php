<?php

namespace App\Models;

use App\ConsoleStatus;
use App\Enums\ConsoleGameStatus;
use App\Enums\ConsoleStatus as ConsoleStatusEnum;
use App\Models\ConsoleGame;
use App\Models\Game;
use App\Models\Order;
use App\Models\User;
use Database\Factories\ConsoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Console extends Model
{
    /** @use HasFactory<ConsoleFactory> */
    use HasFactory;
    protected $casts = [
        'status'=> ConsoleStatus::class,
        // 'games.status' => ConsoleGameStatus::class,
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
public function orders(): HasMany
{
    return $this->hasMany(Order::class);
}
}

