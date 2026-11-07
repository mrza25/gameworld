<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting a user used to delete their orders and payments too, and nothing
     * in the database stopped one payment crediting a wallet twice.
     */
    public function up(): void
    {
        foreach (['orders', 'payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('reference');
        });

        // Only the first row of each reference gets the key, so old data can never block this migration.
        $seen = [];

        DB::table('wallet_transactions')
            ->where(fn ($query) => $query
                ->where('reference', 'like', 'payment-%')
                ->orWhere('reference', 'like', 'order-refund-%'))
            ->orderBy('id')
            ->chunkById(500, function ($transactions) use (&$seen) {
                foreach ($transactions as $transaction) {
                    $key = $transaction->wallet_id.'|'.$transaction->reference;

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;

                    DB::table('wallet_transactions')
                        ->where('id', $transaction->id)
                        ->update(['idempotency_key' => $transaction->reference]);
                }
            });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->unique(['wallet_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        // MySQL now relies on the unique index for the wallet_id foreign key, so it needs its own first.
        if (! Schema::hasIndex('wallet_transactions', ['wallet_id'])) {
            Schema::table('wallet_transactions', function (Blueprint $table) {
                $table->index('wallet_id');
            });
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['wallet_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        foreach (['orders', 'payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
