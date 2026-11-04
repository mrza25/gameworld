<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('pinned_at')->nullable();

            $table->index(['is_pinned', 'pinned_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['is_pinned', 'pinned_at']);
            $table->dropColumn(['is_pinned', 'pinned_at']);
        });
    }
};
