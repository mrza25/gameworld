<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('console_game', function (Blueprint $table) {
            $table->id();

            $table->foreignId('console_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('game_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('status')->default('pending');

            $table->timestamp('installed_at')->nullable();

            $table->timestamps();

            $table->unique(['console_id', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_game');
    }
};
