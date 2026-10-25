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
        Schema::table('games', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('title');
            $table->text('description')->nullable()->after('genre');
            $table->string('developer')->nullable()->after('description');
            $table->date('release_date')->nullable()->after('developer');
            $table->unsignedTinyInteger('age_rating')->nullable()->after('release_date');
            $table->string('image')->nullable()->after('age_rating');
            $table->boolean('is_available')->default(true)->after('image');
        });

        // Generate slugs for existing games
        $games = DB::table('games')->get();
        foreach ($games as $game) {
            $baseSlug = Str::slug($game->title);
            $slug = $baseSlug ?: 'game-'.$game->id;

            // Ensure uniqueness
            $counter = 1;
            while (DB::table('games')->where('slug', $slug)->where('id', '!=', $game->id)->exists()) {
                $slug = $baseSlug.'-'.$counter++;
            }

            DB::table('games')->where('id', $game->id)->update([
                'slug' => $slug,
            ]);
        }

        Schema::table('games', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn([
                'slug',
                'description',
                'developer',
                'release_date',
                'age_rating',
                'image',
                'is_available',
            ]);
        });
    }
};
