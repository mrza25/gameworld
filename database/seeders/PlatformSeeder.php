<?php

namespace Database\Seeders;

use App\Models\Platform;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $platforms = [
            ['name' => 'PS5', 'slug' => 'ps5'],
            ['name' => 'PS5 Copy', 'slug' => 'ps5-copy'],
            ['name' => 'PS4', 'slug' => 'ps4'],
            ['name' => 'PS4 Copy', 'slug' => 'ps4-copy'],
            ['name' => 'Xbox Series X', 'slug' => 'xbox-series-x'],
            ['name' => 'Xbox Series S', 'slug' => 'xbox-series-s'],
            ['name' => 'Xbox Series S Copy', 'slug' => 'xbox-series-s-copy'],
            ['name' => 'Xbox One', 'slug' => 'xbox-one'],
            ['name' => 'Xbox One Copy', 'slug' => 'xbox-one-copy'],
        ];

        foreach ($platforms as $platform) {
            Platform::updateOrCreate(
                ['slug' => $platform['slug']],
                ['name' => $platform['name']]
            );
        }
    }
}

