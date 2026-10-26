<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The restock workflow reads each item's Critical / Minimum / Optimal
     * stock levels. Each column is added only if missing, so this is safe on
     * a database that already has them. Defaults 2 / 5 / 20; Minimum 5 matches
     * the existing "Low Stock" rule for Office Supplies.
     */
    public function up(): void
    {
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
