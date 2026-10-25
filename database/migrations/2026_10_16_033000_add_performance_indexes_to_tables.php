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
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'orders_user_status_idx');
            $table->index(['status', 'created_at'], 'orders_status_created_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['order_id', 'installation_status'], 'order_items_order_install_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'payments_user_status_idx');
        });

        Schema::table('game_variants', function (Blueprint $table) {
            $table->index(['platform_id', 'is_available'], 'game_variants_platform_avail_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_user_status_idx');
            $table->dropIndex('orders_status_created_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_order_install_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_user_status_idx');
        });

        Schema::table('game_variants', function (Blueprint $table) {
            $table->dropIndex('game_variants_platform_avail_idx');
        });
    }
};
