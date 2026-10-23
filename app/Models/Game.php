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

    public function toCatalogArray(?\App\Models\User $currentUser = null): array
    {
        $avgRating = \Illuminate\Support\Facades\DB::table('game_ratings')->where('game_id', $this->id)->avg('rating');
        $ratingCount = \Illuminate\Support\Facades\DB::table('game_ratings')->where('game_id', $this->id)->count();

        $rate = $avgRating ? round((float) $avgRating, 1) : 4.8;
        $count = $ratingCount > 0 ? $ratingCount : 12;

        $userRating = null;
        $user = $currentUser ?? auth('sanctum')->user();
        if ($user) {
            $userRating = \Illuminate\Support\Facades\DB::table('game_ratings')
                ->where('game_id', $this->id)
                ->where('user_id', $user->id)
                ->value('rating');
        }

        $sales = $this->sales ?? $this->orderItems()->count();

        $genreMap = [
            'action' => 'اکشن',
            'adventure' => 'ماجراجویی',
            'avanture' => 'ماجراجویی',
            'rpg' => 'نقش‌آفرینی',
            'horror' => 'ترسناک',
            'sports' => 'ورزشی',
            'racing' => 'مسابقه‌ای',
            'shooter' => 'شوتِر',
            'strategy' => 'استراتژی',
            'fighting' => 'مبارزه‌ای',
            'open world' => 'جهان‌باز',
        ];

        $rawGenre = (string) ($this->genre ?? 'اکشن');
        $persianGenre = $genreMap[strtolower(trim($rawGenre))] ?? $rawGenre;

        $developer = $this->developer ?: 'PlayStation Studios';
        $releaseDate = $this->release_date ? $this->release_date->format('Y-m-d') : '2023-10-20';
        $releaseDateJalali = $this->release_date ? \App\Helpers\Jalali::fromDateTime($this->release_date) : '۱۴۰۲/۰۷/۲۸';

        $defaultScreenshots = [
            url('storage/screenshots/gow-1.jpg'),
            url('storage/screenshots/gow-2.jpg'),
        ];

        $screenshots = [];
        if (!empty($this->screenshots) && is_array($this->screenshots)) {
            foreach ($this->screenshots as $img) {
                if (empty($img)) continue;
                $screenshots[] = \Illuminate\Support\Str::startsWith($img, ['http://', 'https://'])
                    ? $img
                    : url(ltrim($img, '/'));
            }
        }

        if (empty($screenshots)) {
            $screenshots = $defaultScreenshots;
        }

        $variants = $this->relationLoaded('variants') ? $this->variants : $this->variants()->with('platform')->get();

        return [
            'id' => $this->id,
            'name' => $this->title,
            'title' => $this->title,
            'slug' => $this->slug,
            'genre' => $persianGenre,
            'genre_raw' => $rawGenre,
            'genreRaw' => $rawGenre,
            'brand' => $developer,
            'developer' => $developer,
            'releaseDate' => $releaseDate,
            'release_date' => $releaseDate,
            'releaseDateJalali' => $releaseDateJalali,
            'age' => $this->age_rating ?? 16,
            'available' => (bool) $this->is_available,
            'description' => $this->description,
            'rate' => (float) $rate,
            'ratingCount' => (int) $count,
            'userRating' => $userRating ? (int) $userRating : null,
            'user_rating' => $userRating ? (int) $userRating : null,
            'sales' => (int) $sales,
            'modes' => $this->modes ?? ['تک‌نفره', 'آنلاین'],
            'languages' => $this->languages ?? ['انگلیسی', 'زیرنویس فارسی'],
            'image' => $this->image
                ? (\Illuminate\Support\Str::startsWith($this->image, ['http://', 'https://']) ? $this->image : url('storage/' . ltrim($this->image, '/')))
                : url('/images/logosite.png'),
            'screenshots' => $screenshots,
            'platforms' => $variants->map(fn ($variant) => [
                'id' => $variant->id,
                'variant_id' => $variant->id,
                'console' => $variant->platform?->slug ?? 'other',
                'console_name' => $variant->platform?->name ?? 'کنسول',
                'size' => (float) $variant->size,
                'price' => (float) $variant->price,
                'is_available' => (bool) $variant->is_available,
            ])->values()->all(),
        ];
    }


}
