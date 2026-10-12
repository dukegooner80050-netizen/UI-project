<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each column is added only if missing, so this is safe to run on a
        // database that already has some or all of them.
        foreach ([
            'critical_stock' => 2,
            'minimum_stock' => 5,
            'optimal_stock' => 20,
        ] as $column => $default) {
            if (!Schema::hasColumn('items', $column)) {
                Schema::table('items', function (Blueprint $table) use ($column, $default) {
                    $table->unsignedInteger($column)->default($default);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['critical_stock', 'minimum_stock', 'optimal_stock'] as $column) {
            if (Schema::hasColumn('items', $column)) {
                Schema::table('items', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
