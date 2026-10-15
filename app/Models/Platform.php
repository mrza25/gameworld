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
}
