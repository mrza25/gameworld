<?php

namespace App\Models;

use App\Enums\ConsoleGameStatus;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ConsoleGame extends Pivot
{
    protected $table = 'console_game';

    public $incrementing = true;

    protected $fillable = [
        'console_id',
        'game_id',
        'status',
        'installed_at',
    ];

    protected $casts = [
        'status' => ConsoleGameStatus::class,
        'installed_at' => 'datetime',
    ];
}
