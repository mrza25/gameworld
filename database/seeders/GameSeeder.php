<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\Platform;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GameSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $games = [
            1 => [
                'title' => 'God of War Ragnarök',
                'slug' => 'god-of-war-ragnarok',
                'genre' => 'اکشن',
                'developer' => 'Santa Monica Studio',
                'release_date' => '2022-11-09',
                'description' => 'سفری حماسی همراه با کریتوس و آترئوس در میان نُه قلمرو نورس برای مقابله با رگناروک.',
                'screenshots' => json_encode(['storage/screenshots/gow-1.jpg', 'storage/screenshots/gow-2.jpg']),
                'image' => 'storage/screenshots/gow-1.jpg',
                'platform' => 'PlayStation 5',
                'size' => 84.0,
                'price' => 1500000,
                'age_rating' => 18,
                'is_available' => true,
            ],
            2 => [
                'title' => 'The Last of Us Part I',
                'slug' => 'the-last-of-us-part-i',
                'genre' => 'ماجراجویی',
                'developer' => 'Naughty Dog',
                'release_date' => '2022-09-02',
                'description' => 'روایت ماندگار و عمیق جوئل و الی در جهانی ویران‌شده با بازسازی نسل نهمی کامل.',
                'screenshots' => json_encode(['storage/screenshots/tlou-1.jpg', 'storage/screenshots/tlou-2.jpg']),
                'image' => 'storage/screenshots/tlou-1.jpg',
                'platform' => 'PlayStation 5',
                'size' => 68.0,
                'price' => 1400000,
                'age_rating' => 18,
                'is_available' => true,
            ],
            3 => [
                'title' => 'OD (Overdose)',
                'slug' => 'od-overdose',
                'genre' => 'ترسناک',
                'developer' => 'Kojima Productions',
                'release_date' => '2025-10-15',
                'description' => 'پروژه مفهومی و ترسناک سینمایی هیدئو کوجیما با فناوری ابری و گرافیک فوتورئالیستیک.',
                'screenshots' => json_encode(['storage/screenshots/od-1.jpg']),
                'image' => 'storage/screenshots/od-1.jpg',
                'platform' => 'PlayStation 5',
                'size' => 55.0,
                'price' => 1600000,
                'age_rating' => 18,
                'is_available' => true,
            ],
            4 => [
                'title' => "Death Stranding Director's Cut",
                'slug' => 'death-stranding-directors-cut',
                'genre' => 'نقش‌آفرینی',
                'developer' => 'Kojima Productions',
                'release_date' => '2021-09-24',
                'description' => 'تجربه‌ای منحصربه‌فرد از برقراری پیوند میان بازماندگان جامعه بشری در دنیایی پسا‌آخرالزمانی.',
                'screenshots' => json_encode(['storage/screenshots/deathstranding-1.jpg']),
                'image' => 'storage/screenshots/deathstranding-1.jpg',
                'platform' => 'PlayStation 5',
                'size' => 65.0,
                'price' => 1200000,
                'age_rating' => 16,
                'is_available' => true,
            ],
            5 => [
                'title' => 'Alan Wake 2',
                'slug' => 'alan-wake-2',
                'genre' => 'ماجراجویی',
                'developer' => 'Remedy Entertainment',
                'release_date' => '2023-10-27',
                'description' => 'شاهکار ترسناک بقا و معمایی روانشناختی که نامزد و برنده چندین جایزه برتر سال شد.',
                'screenshots' => json_encode(['storage/screenshots/alanwake-1.jpg']),
                'image' => 'storage/screenshots/alanwake-1.jpg',
                'platform' => 'PlayStation 5',
                'size' => 80.0,
                'price' => 1750000,
                'age_rating' => 18,
                'is_available' => true,
            ],
        ];

        $now = now();
        foreach ($games as $id => $data) {
            DB::table('games')->updateOrInsert(
                ['id' => $id],
                array_merge($data, [
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
        }

        $baseSizes = [
            1 => 84.0,
            2 => 68.0,
            3 => 55.0,
            4 => 65.0,
            5 => 80.0,
        ];

        $basePrices = [
            1 => 1500000,
            2 => 1400000,
            3 => 1600000,
            4 => 1200000,
            5 => 1750000,
        ];

        $allGames = Game::all();
        $platforms = Platform::all();

        foreach ($allGames as $game) {
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
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }
}
