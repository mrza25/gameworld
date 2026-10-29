<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Platform extends Model
{
    public const CONSOLES_CACHE_KEY = 'catalog:consoles';

    protected $fillable = [
        'name',
        'slug',
        'short',
        'brand',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CONSOLES_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CONSOLES_CACHE_KEY));
    }

    public function gameVariants(): HasMany
    {
        return $this->hasMany(GameVariant::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? (is_numeric($value) ? 'id' : 'slug'), $value)->firstOrFail();
    }

    /**
     * Find a platform by its exact name or slug, querying the (small)
     * platforms table only once per request.
     */
    public static function findByNameOrSlug(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return once(fn () => static::query()->orderBy('id')->get())
            ->first(fn (Platform $platform) => $platform->name === $value || $platform->slug === $value);
    }

    /**
     * Whether this is the "copy" (کپی‌خور) edition of a console.
     */
    public function isCopyEdition(): bool
    {
        return Str::endsWith($this->slug, '-copy');
    }

    /**
     * The console family shared by the standard and copy editions, e.g. "ps5" for "ps5-copy".
     */
    public function family(): string
    {
        return $this->isCopyEdition() ? Str::beforeLast($this->slug, '-copy') : $this->slug;
    }

    public function resolvedBrand(): string
    {
        if ($this->brand) {
            return $this->brand;
        }

        return match (true) {
            Str::startsWith($this->slug, 'ps') => 'playstation',
            Str::startsWith($this->slug, 'xbox') => 'xbox',
            default => 'other',
        };
    }

    /**
     * Console as exposed to the frontend (console pickers, filters and badges).
     *
     * @return array{id: int, value: string, label: string, short: string, brand: string, family: string, mode: string, sort_order: int}
     */
    public function toConsoleArray(): array
    {
        return [
            'id' => $this->id,
            'value' => $this->slug,
            'label' => $this->name,
            'short' => $this->short ?: $this->name,
            'brand' => $this->resolvedBrand(),
            'family' => $this->family(),
            'mode' => $this->isCopyEdition() ? 'copy' : 'standard',
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
