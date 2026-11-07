<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a bank statement shows for a transfer. Unlike the receipt file's hash,
     * cropping or re-saving the photo does not change these.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('tracking_code', 40)->nullable()->index()->after('receipt_uploaded_at');
            $table->string('card_last4', 4)->nullable()->after('tracking_code');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['tracking_code']);
            $table->dropColumn(['tracking_code', 'card_last4']);
        });
    }
};
