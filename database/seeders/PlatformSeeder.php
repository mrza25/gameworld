<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $platforms = [
            ['name' => 'PlayStation 5', 'slug' => 'ps5'],
            ['name' => 'PlayStation 5 (کپی‌خور)', 'slug' => 'ps5-copy'],
            ['name' => 'PlayStation 4', 'slug' => 'ps4'],
            ['name' => 'PlayStation 4 (کپی‌خور)', 'slug' => 'ps4-copy'],
            ['name' => 'Xbox Series X', 'slug' => 'xbox-series-x'],
            ['name' => 'Xbox Series X (کپی‌خور)', 'slug' => 'xbox-series-x-copy'],
            ['name' => 'Xbox Series S', 'slug' => 'xbox-series-s'],
            ['name' => 'Xbox Series S (کپی‌خور)', 'slug' => 'xbox-series-s-copy'],
            ['name' => 'Xbox One', 'slug' => 'xbox-one'],
            ['name' => 'Xbox One (کپی‌خور)', 'slug' => 'xbox-one-copy'],
            ['name' => 'سایر کنسول‌ها', 'slug' => 'other'],
        ];

        $now = now();
        foreach ($platforms as $p) {
            DB::table('platforms')->updateOrInsert(
                ['slug' => $p['slug']],
                [
                    'name' => $p['name'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
