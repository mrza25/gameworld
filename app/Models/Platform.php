<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Platform extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function gameVariants(): HasMany
    {
        return $this->hasMany(GameVariant::class);
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
}
