<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->unsignedInteger('found_quantity')->default(0)->after('status');
            $table->dateTime('found_at')->nullable()->after('found_quantity');
            $table->string('found_by_name', 100)->nullable()->after('found_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropColumn(['found_quantity', 'found_at', 'found_by_name']);
        });
    }
};
