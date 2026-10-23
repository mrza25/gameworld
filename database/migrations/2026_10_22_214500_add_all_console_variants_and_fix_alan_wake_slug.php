<?php

use App\Models\Game;
use App\Models\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        // 1. Fix Alan Wake 2 slug
        DB::table('games')->where('id', 5)->update([
            'title' => 'Alan Wake 2',
            'slug' => 'alan-wake-2',
        ]);

        // 2. Ensure all 11 platforms exist
        $platformDefs = [
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

        foreach ($platformDefs as $p) {
            DB::table('platforms')->updateOrInsert(
                ['slug' => $p['slug']],
                [
                    'name' => $p['name'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // 3. Populate variants for all 5 games across all 11 platforms
        $baseSizes = [
            1 => 84.0, // God of War
            2 => 68.0, // The Last of Us
            3 => 55.0, // OD
            4 => 65.0, // Death Stranding
            5 => 80.0, // Alan Wake 2
        ];

        $basePrices = [
            1 => 1500000,
            2 => 1400000,
            3 => 1600000,
            4 => 1200000,
            5 => 1750000,
        ];

        $games = Game::all();
        $platforms = Platform::all();

        foreach ($games as $game) {
            $basePrice = $basePrices[$game->id] ?? 1200000;
            $baseSize = $baseSizes[$game->id] ?? 60.0;

            foreach ($platforms as $platform) {
                $isCopy = str_contains($platform->slug, 'copy') || $platform->slug === 'other';
                $price = $isCopy ? round($basePrice * 0.35) : $basePrice;

                DB::table('game_variants')->updateOrInsert(
                    [
                        'game_id' => $game->id,
                        'platform_id' => $platform->id,
                    ],
                    [
                        'price' => $price,
                        'size' => $baseSize,
                        'is_available' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
