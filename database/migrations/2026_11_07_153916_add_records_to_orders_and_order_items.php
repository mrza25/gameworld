<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Orders get a public number and their price breakdown, and order items keep
     * what was bought, so renaming or removing a game never changes an old order.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('number', 20)->nullable()->unique()->after('id');
            $table->decimal('subtotal', 15, 2)->nullable()->after('console_note');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('subtotal');
            $table->string('coupon_code', 32)->nullable()->after('discount_amount');
            $table->decimal('total', 15, 2)->default(0)->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('title')->nullable()->after('game_variant_id');
            $table->string('platform_name')->nullable()->after('title');
        });

        DB::table('orders')->orderBy('id')->chunkById(200, function ($orders) {
            foreach ($orders as $order) {
                DB::table('orders')->where('id', $order->id)->update([
                    'number' => 'GW-'.date('ymd', strtotime((string) $order->created_at) ?: time()).'-'.Str::upper(Str::random(5)),
                    'subtotal' => $order->total,
                ]);
            }
        });

        DB::table('order_items')
            ->leftJoin('games', 'games.id', '=', 'order_items.game_id')
            ->leftJoin('game_variants', 'game_variants.id', '=', 'order_items.game_variant_id')
            ->leftJoin('platforms', 'platforms.id', '=', 'game_variants.platform_id')
            ->select('order_items.id', 'games.title as game_title', 'platforms.name as platform')
            ->orderBy('order_items.id')
            ->chunkById(200, function ($items) {
                foreach ($items as $item) {
                    DB::table('order_items')->where('id', $item->id)->update([
                        'title' => $item->game_title,
                        'platform_name' => $item->platform,
                    ]);
                }
            }, 'order_items.id', 'id');
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['title', 'platform_name']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn(['number', 'subtotal', 'discount_amount', 'coupon_code']);
            $table->decimal('total', 10, 2)->default(0)->change();
        });
    }
};
