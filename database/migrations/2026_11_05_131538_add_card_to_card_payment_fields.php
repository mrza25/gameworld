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
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_path')->nullable();
            $table->string('receipt_hash', 64)->nullable()->index();
            $table->timestamp('receipt_uploaded_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('payment_due_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_due_at']);
            $table->dropColumn('payment_due_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropIndex(['receipt_hash']);
            $table->dropColumn(['receipt_path', 'receipt_hash', 'receipt_uploaded_at', 'reviewed_at', 'review_note']);
        });
    }
};
