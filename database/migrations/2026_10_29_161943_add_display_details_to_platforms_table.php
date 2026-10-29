<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Display details for the consoles that already exist.
     *
     * @var array<string, array{short: string, brand: string}>
     */
    private array $knownConsoles = [
        'ps5' => ['short' => 'PS5', 'brand' => 'playstation'],
        'ps5-copy' => ['short' => 'PS5 کپی‌خور', 'brand' => 'playstation'],
        'ps4' => ['short' => 'PS4', 'brand' => 'playstation'],
        'ps4-copy' => ['short' => 'PS4 کپی‌خور', 'brand' => 'playstation'],
        'xbox-series-x' => ['short' => 'Series X', 'brand' => 'xbox'],
        'xbox-series-x-copy' => ['short' => 'Series X کپی‌خور', 'brand' => 'xbox'],
        'xbox-series-s' => ['short' => 'Series S', 'brand' => 'xbox'],
        'xbox-series-s-copy' => ['short' => 'Series S کپی‌خور', 'brand' => 'xbox'],
        'xbox-one' => ['short' => 'Xbox One', 'brand' => 'xbox'],
        'xbox-one-copy' => ['short' => 'Xbox One کپی‌خور', 'brand' => 'xbox'],
        'other' => ['short' => 'سایر', 'brand' => 'other'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->string('short', 50)->nullable()->after('slug');
            $table->string('brand', 30)->nullable()->after('short');
            $table->unsignedInteger('sort_order')->default(0)->after('brand');
        });

        foreach (DB::table('platforms')->orderBy('id')->get(['id', 'slug']) as $position => $platform) {
            DB::table('platforms')->where('id', $platform->id)->update([
                'short' => $this->knownConsoles[$platform->slug]['short'] ?? null,
                'brand' => $this->knownConsoles[$platform->slug]['brand'] ?? null,
                'sort_order' => ($position + 1) * 10,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table) {
            $table->dropColumn(['short', 'brand', 'sort_order']);
        });
    }
};
