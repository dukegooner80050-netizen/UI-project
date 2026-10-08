<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_inspections', function (Blueprint $table) {
            $table->integer('fit_for_use_qty')->nullable()->after('quantity');
            $table->integer('maintenance_qty')->nullable()->after('fit_for_use_qty');
            $table->integer('disposal_qty')->nullable()->after('maintenance_qty');
        });
    }

    public function down(): void
    {
        Schema::table('pending_inspections', function (Blueprint $table) {
            $table->dropColumn(['fit_for_use_qty', 'maintenance_qty', 'disposal_qty']);
        });
    }
};
