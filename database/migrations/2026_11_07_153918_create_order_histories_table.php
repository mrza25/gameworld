<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A record of everything that happened to an order and who did it. Rows are
     * only ever added. A null user_id means the system did it, such as a deadline.
     */
    public function up(): void
    {
        Schema::create('order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40);
            $table->string('from_value', 40)->nullable();
            $table->string('to_value', 40)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });

        DB::table('orders')->orderBy('id')->chunkById(200, function ($orders) {
            DB::table('order_histories')->insert($orders->map(fn ($order) => [
                'order_id' => $order->id,
                'event' => 'created',
                'to_value' => $order->status,
                'created_at' => $order->created_at,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_histories');
    }
};
