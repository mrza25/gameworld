<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Arabic ي and ك look the same as Persian ی and ک but never match them in a
     * search. New input is converted on arrival, and this fixes what is stored.
     * It cannot be told apart afterwards, so there is nothing to undo.
     */
    public function up(): void
    {
        $normalized = fn (string $column) => DB::raw(
            "REPLACE(REPLACE(REPLACE({$column}, 'ي', 'ی'), 'ى', 'ی'), 'ك', 'ک')"
        );

        DB::table('games')->update([
            'title' => $normalized('title'),
            'genre' => $normalized('genre'),
            'developer' => $normalized('developer'),
            'description' => $normalized('description'),
        ]);
    }

    public function down(): void
    {
        //
    }
};
