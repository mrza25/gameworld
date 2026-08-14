<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class Game extends Model
{
    /** @use HasFactory<\Database\Factories\GameFactory> */
    use HasFactory;
    public function consoles(): BelongsToMany
    {
        return $this->belongsToMany(Console::class, 'console_game', 'game_id', 'console_id')
            ->withPivot('status')
            ->withTimestamps();
    }
}
