<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            if (!Schema::hasColumn('games', 'modes')) {
                $table->json('modes')->nullable()->after('age_rating');
            }
            if (!Schema::hasColumn('games', 'languages')) {
                $table->json('languages')->nullable()->after('modes');
            }
            if (!Schema::hasColumn('games', 'screenshots')) {
                $table->json('screenshots')->nullable()->after('languages');
            }
            if (!Schema::hasColumn('games', 'sales')) {
                $table->unsignedInteger('sales')->default(0)->after('screenshots');
            }
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['modes', 'languages', 'screenshots', 'sales']);
        });
    }
};

