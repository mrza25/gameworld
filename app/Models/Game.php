<?php

namespace App\Models;

use App\Helpers\Jalali;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Game extends Model
{
    /** @use HasFactory<GameFactory> */
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

    public function ratings(): HasMany
    {
        return $this->hasMany(GameRating::class);
    }

    /**
     * Preload the aggregates used by toCatalogArray() so listing many games
     * does not run several extra queries per game.
     */
    public function scopeWithCatalogStats(Builder $query, ?User $user = null): Builder
    {
        $query->with(['variants.platform'])
            ->withAvg('ratings', 'rating')
            ->withCount(['ratings', 'orderItems']);

        if ($user) {
            $query->addSelect([
                'user_rating' => GameRating::query()
                    ->select('rating')
                    ->whereColumn('game_id', 'games.id')
                    ->where('user_id', $user->id)
                    ->limit(1),
            ]);
        }

        return $query;
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
            if (empty($game->slug) && ! empty($game->title)) {
                $baseSlug = Str::slug($game->title) ?: 'game-'.uniqid();
                $slug = $baseSlug;
                $counter = 1;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $baseSlug.'-'.$counter++;
                }
                $game->slug = $slug;
            }
        });
    }

    /**
     * Normalize an image reference for storage. Our own storage URLs (on any
     * host) and legacy "storage/..." paths become public-disk paths, so they
     * keep working when the API host changes. External URLs are kept as-is.
     */
    public static function normalizeImageReference(string $reference): string
    {
        $reference = trim($reference);

        if (Str::startsWith($reference, ['http://', 'https://'])) {
            $path = (string) parse_url($reference, PHP_URL_PATH);

            return Str::startsWith($path, '/storage/') ? Str::after($path, '/storage/') : $reference;
        }

        $path = ltrim($reference, '/');

        return Str::startsWith($path, 'storage/') ? Str::after($path, 'storage/') : $reference;
    }

    /**
     * Build the public URL for a stored image reference: an external URL, a
     * public-disk path, or an asset under the public directory ("/images/...").
     */
    public static function publicImageUrl(string $reference): string
    {
        $normalized = self::normalizeImageReference($reference);

        if (Str::startsWith($normalized, ['http://', 'https://'])) {
            return $normalized;
        }

        if (Str::startsWith($normalized, '/')) {
            return url($normalized);
        }

        return url('storage/'.$normalized);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? (is_numeric($value) ? 'id' : 'slug'), $value)->firstOrFail();
    }

    public function toCatalogArray(?User $currentUser = null): array
    {
        $attributes = $this->getAttributes();

        $avgRating = array_key_exists('ratings_avg_rating', $attributes)
            ? $attributes['ratings_avg_rating']
            : $this->ratings()->avg('rating');
        $ratingCount = array_key_exists('ratings_count', $attributes)
            ? (int) $attributes['ratings_count']
            : $this->ratings()->count();

        $rate = $avgRating !== null ? round((float) $avgRating, 1) : 0.0;
        $count = $ratingCount;

        $userRating = null;
        $user = $currentUser ?? auth('sanctum')->user();
        if ($user) {
            $userRating = array_key_exists('user_rating', $attributes)
                ? $attributes['user_rating']
                : $this->ratings()->where('user_id', $user->id)->value('rating');
        }

        $sales = $this->sales ?? (array_key_exists('order_items_count', $attributes)
            ? (int) $attributes['order_items_count']
            : $this->orderItems()->count());

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
        $releaseDateJalali = $this->release_date ? Jalali::fromDateTime($this->release_date) : '۱۴۰۲/۰۷/۲۸';

        $screenshots = collect(is_array($this->screenshots) ? $this->screenshots : [])
            ->filter(fn ($img) => is_string($img) && $img !== '')
            ->map(fn (string $img) => self::publicImageUrl($img))
            ->values()
            ->all();

        $variants = $this->relationLoaded('variants') ? $this->variants : $this->variants()->with('platform')->get();

        $variantList = $variants->map(fn ($variant) => [
            'id' => $variant->id,
            'variant_id' => $variant->id,
            'platform_id' => $variant->platform_id,
            'console' => $variant->platform?->slug ?? 'other',
            'platform' => $variant->platform?->slug ?? 'other',
            'console_name' => $variant->platform?->name ?? 'کنسول',
            'platform_name' => $variant->platform?->name ?? 'کنسول',
            'size' => (float) $variant->size,
            'price' => (float) $variant->price,
            'is_available' => (bool) $variant->is_available,
            'available' => (bool) $variant->is_available,
        ])->values()->all();

        $firstVariant = $variants->first();
        $basePrice = (float) ($this->price > 0 ? $this->price : ($firstVariant?->price ?? 0));
        $baseSize = (float) ($this->size > 0 ? $this->size : ($firstVariant?->size ?? 0));
        $basePlatform = $this->platform ?? ($firstVariant?->platform?->name ?? 'PlayStation 5');
        $basePlatformSlug = $firstVariant?->platform?->slug ?? 'ps5';

        $imageUrl = $this->image
            ? self::publicImageUrl($this->image)
            : url('/images/logosite.png');

        return [
            'id' => $this->id,
            'name' => $this->title,
            'title' => $this->title,
            'slug' => $this->slug,
            'genre' => $persianGenre,
            'genre_raw' => $rawGenre,
            'genreRaw' => $rawGenre,
            'price' => $basePrice,
            'size' => $baseSize,
            'platform' => $basePlatform,
            'platform_slug' => $basePlatformSlug,
            'console' => $basePlatformSlug,
            'brand' => $developer,
            'developer' => $developer,
            'releaseDate' => $releaseDate,
            'release_date' => $releaseDate,
            'releaseDateJalali' => $releaseDateJalali,
            'age' => (int) ($this->age_rating ?? 16),
            'age_rating' => (int) ($this->age_rating ?? 16),
            'available' => (bool) $this->is_available,
            'is_available' => (bool) $this->is_available,
            'description' => $this->description ?? '',
            'rate' => (float) $rate,
            'ratingCount' => (int) $count,
            'userRating' => $userRating ? (int) $userRating : null,
            'user_rating' => $userRating ? (int) $userRating : null,
            'sales' => (int) $sales,
            'modes' => (! empty($this->modes) && is_array($this->modes)) ? array_values($this->modes) : ['تک‌نفره', 'آنلاین'],
            'languages' => (! empty($this->languages) && is_array($this->languages)) ? array_values($this->languages) : ['انگلیسی', 'زیرنویس فارسی'],
            'image' => $imageUrl,
            'cover' => $imageUrl,
            'poster' => $imageUrl,
            'screenshots' => $screenshots,
            'platforms' => $variantList,
            'variants' => $variantList,
        ];
    }
}
