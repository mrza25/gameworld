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
        $games = [
            1 => [
                'title' => 'God of War Ragnarök',
                'genre' => 'اکشن',
                'developer' => 'Santa Monica Studio',
                'release_date' => '2022-11-09',
                'description' => 'سفری حماسی همراه با کریتوس و آترئوس در میان نُه قلمرو نورس برای مقابله با رگناروک.',
                'screenshots' => json_encode(['storage/screenshots/gow-1.jpg', 'storage/screenshots/gow-2.jpg']),
            ],
            2 => [
                'title' => 'The Last of Us Part I',
                'genre' => 'ماجراجویی',
                'developer' => 'Naughty Dog',
                'release_date' => '2022-09-02',
                'description' => 'روایت ماندگار و عمیق جوئل و الی در جهانی ویران‌شده با بازسازی نسل نهمی کامل.',
                'screenshots' => json_encode(['storage/screenshots/tlou-1.jpg', 'storage/screenshots/tlou-2.jpg']),
            ],
            3 => [
                'title' => 'OD (Overdose)',
                'genre' => 'ترسناک',
                'developer' => 'Kojima Productions',
                'release_date' => '2025-10-15',
                'description' => 'پروژه مفهومی و ترسناک سینمایی هیدئو کوجیما با فناوری ابری و گرافیک فوتورئالیستیک.',
                'screenshots' => json_encode(['storage/screenshots/od-1.jpg']),
            ],
            4 => [
                'title' => "Death Stranding Director's Cut",
                'genre' => 'نقش‌آفرینی',
                'developer' => 'Kojima Productions',
                'release_date' => '2021-09-24',
                'description' => 'تجربه‌ای منحصربه‌فرد از برقراری پیوند میان بازماندگان جامعه بشری در دنیایی پسا‌آخرالزمانی.',
                'screenshots' => json_encode(['storage/screenshots/deathstranding-1.jpg']),
            ],
            5 => [
                'title' => 'Alan Wake 2',
                'genre' => 'ماجراجویی',
                'developer' => 'Remedy Entertainment',
                'release_date' => '2023-10-27',
                'description' => 'شاهکار ترسناک بقا و معمایی روانشناختی که نامزد و برنده چندین جایزه برتر سال شد.',
                'screenshots' => json_encode(['storage/screenshots/alanwake-1.jpg']),
            ],
        ];

        foreach ($games as $id => $data) {
            DB::table('games')->where('id', $id)->update($data);
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
