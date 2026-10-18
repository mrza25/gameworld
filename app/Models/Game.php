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
        return $this->belongsToMany(Console::class, 'console_game', 'game_id', 'console_id')
            ->using(ConsoleGame::class)
            ->withPivot('status', 'installed_at')
            ->withTimestamps();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function variants(): HasMany
{
    return $this->hasMany(GameVariant::class);
}

    protected $fillable = [
        'title',
        'slug',
        'genre',
        'description',
        'developer',
        'release_date',
        'age_rating',
        'image',
        'is_available',
        'modes',
        'languages',
        'screenshots',
        'sales',
        'platform',
        'size',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'size' => 'decimal:2',
        'release_date' => 'date',
        'age_rating' => 'integer',
        'is_available' => 'boolean',
        'modes' => 'array',
        'languages' => 'array',
        'screenshots' => 'array',
        'sales' => 'integer',
    ];

    protected $attributes = [
        'platform' => 'PS5',
        'size' => 0,
        'price' => 0,
        'is_available' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (Game $game) {
            if (empty($game->slug) && !empty($game->title)) {
                $baseSlug = \Illuminate\Support\Str::slug($game->title) ?: 'game-' . uniqid();
                $slug = $baseSlug;
                $counter = 1;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $counter++;
                }
                $game->slug = $slug;
            }
        });
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? (is_numeric($value) ? 'id' : 'slug'), $value)->firstOrFail();
    }


}
