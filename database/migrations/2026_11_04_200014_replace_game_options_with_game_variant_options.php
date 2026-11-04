<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Options move from the game to each of its variants and gain a price. The
 * previous tables are replaced rather than converted: the feature was never
 * released, so they hold no data worth keeping.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('game_options');

        Schema::create('game_variant_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_variant_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100)->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['game_variant_id', 'is_active', 'sort_order']);
        });

        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('game_variant_option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 100)->nullable();
            $table->decimal('price', 10, 2);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('game_variant_options');

        Schema::create('game_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 100);
        });
    }
};
