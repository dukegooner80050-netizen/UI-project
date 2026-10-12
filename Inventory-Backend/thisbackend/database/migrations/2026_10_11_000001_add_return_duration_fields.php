<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-item return period (days). Null = fall back to category default.
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedSmallInteger('return_days')->nullable()->after('damaged_quantity');
        });

        // Due date stamped when the request is approved.
        Schema::table('requestitem', function (Blueprint $table) {
            $table->date('due_at')->nullable()->after('returned_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('requestitem', function (Blueprint $table) {
            $table->dropColumn('due_at');
        });
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('return_days');
        });
    }
};
