<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ConsoleGame extends Pivot
{
    protected $table = 'console_game';

    protected $fillable = [
        'console_id',
        'game_id',
        'status',
        'installed_at',
    ];

    protected $casts = [
        'installed_at' => 'datetime',
    ];
}
