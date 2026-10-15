<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('game_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('game_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('platform_id')
                ->constrained()
                ->restrictOnDelete();

            $table->decimal('price', 10, 2);
            $table->decimal('size', 10, 2);
            $table->boolean('is_available')->default(true);

            $table->timestamps();

            $table->unique(['game_id', 'platform_id']);
        });

        // انتقال اطلاعات فعلی بازی‌ها به نسخه‌های اولیه
        $games = DB::table('games')->get();

        foreach ($games as $game) {
            $slug = Str::slug($game->platform);

            if ($slug === '') {
                continue;
            }

            $now = now();

            DB::table('platforms')->updateOrInsert(
                ['slug' => $slug],
                [
                    'name' => strtoupper($game->platform),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $platform = DB::table('platforms')
                ->where('slug', $slug)
                ->first();

            DB::table('game_variants')->updateOrInsert(
                [
                    'game_id' => $game->id,
                    'platform_id' => $platform->id,
                ],
                [
                    'price' => $game->price,
                    'size' => $game->size,
                    'is_available' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_variants');
        Schema::dropIfExists('platforms');
    }
};
