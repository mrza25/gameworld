<?php

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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
