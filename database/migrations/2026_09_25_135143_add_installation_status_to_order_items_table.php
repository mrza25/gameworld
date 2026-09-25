<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('installation_status')
                ->default('pending')
                ->after('price');

            $table->timestamp('installed_at')
                ->nullable()
                ->after('installation_status');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'installation_status',
                'installed_at',
            ]);
        });
    }
};
