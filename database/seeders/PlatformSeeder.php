<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $platforms = [
            ['name' => 'PlayStation 5', 'slug' => 'ps5', 'short' => 'PS5', 'brand' => 'playstation'],
            ['name' => 'PlayStation 5 (کپی‌خور)', 'slug' => 'ps5-copy', 'short' => 'PS5 کپی‌خور', 'brand' => 'playstation'],
            ['name' => 'PlayStation 4', 'slug' => 'ps4', 'short' => 'PS4', 'brand' => 'playstation'],
            ['name' => 'PlayStation 4 (کپی‌خور)', 'slug' => 'ps4-copy', 'short' => 'PS4 کپی‌خور', 'brand' => 'playstation'],
            ['name' => 'Xbox Series X', 'slug' => 'xbox-series-x', 'short' => 'Series X', 'brand' => 'xbox'],
            ['name' => 'Xbox Series X (کپی‌خور)', 'slug' => 'xbox-series-x-copy', 'short' => 'Series X کپی‌خور', 'brand' => 'xbox'],
            ['name' => 'Xbox Series S', 'slug' => 'xbox-series-s', 'short' => 'Series S', 'brand' => 'xbox'],
            ['name' => 'Xbox Series S (کپی‌خور)', 'slug' => 'xbox-series-s-copy', 'short' => 'Series S کپی‌خور', 'brand' => 'xbox'],
            ['name' => 'Xbox One', 'slug' => 'xbox-one', 'short' => 'Xbox One', 'brand' => 'xbox'],
            ['name' => 'Xbox One (کپی‌خور)', 'slug' => 'xbox-one-copy', 'short' => 'Xbox One کپی‌خور', 'brand' => 'xbox'],
            ['name' => 'سایر کنسول‌ها', 'slug' => 'other', 'short' => 'سایر', 'brand' => 'other'],
        ];

        $now = now();
        foreach ($platforms as $position => $p) {
            DB::table('platforms')->updateOrInsert(
                ['slug' => $p['slug']],
                [
                    'name' => $p['name'],
                    'short' => $p['short'],
                    'brand' => $p['brand'],
                    'sort_order' => ($position + 1) * 10,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
