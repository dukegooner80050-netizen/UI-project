<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-item stock thresholds (roadmap #6).
     *
     *   quantity <= 0                 -> Out of Stock
     *   quantity <= critical_stock    -> Critical
     *   quantity <= minimum_stock     -> Minimum
     *   quantity >= optimal_stock     -> Optimal
     *   anything in between           -> Adequate
     *
     * Defaults are applied to every existing row too. minimum_stock = 5
     * matches the old hard-coded "5 or fewer = Low Stock" rule, so existing
     * items behave the same until an admin edits their levels.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedInteger('critical_stock')->default(2)->after('maintenance_quantity');
            $table->unsignedInteger('minimum_stock')->default(5)->after('critical_stock');
            $table->unsignedInteger('optimal_stock')->default(20)->after('minimum_stock');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['critical_stock', 'minimum_stock', 'optimal_stock']);
        });
    }
};
