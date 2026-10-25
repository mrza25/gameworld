<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add console_note to orders if not exists
        if (! Schema::hasColumn('orders', 'console_note')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('console_note')->nullable()->after('console_type');
            });
        }

        // 2. Add avatar to users if not exists
        if (! Schema::hasColumn('users', 'avatar')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('avatar')->nullable()->after('name');
            });
        }

        // 3. Ensure platforms table has the 11 platforms (in non-testing environments)
        if (! app()->runningUnitTests()) {
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

        // 4. Create banners table
        if (! Schema::hasTable('banners')) {
            Schema::create('banners', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('image');
                $table->string('link')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // 5. Create game_ratings table
        if (! Schema::hasTable('game_ratings')) {
            Schema::create('game_ratings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('game_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('rating'); // 1-5
                $table->text('comment')->nullable();
                $table->timestamps();
                $table->unique(['game_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_ratings');
        Schema::dropIfExists('banners');

        if (Schema::hasColumn('users', 'avatar')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('avatar');
            });
        }

        if (Schema::hasColumn('orders', 'console_note')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('console_note');
            });
        }
    }
};
