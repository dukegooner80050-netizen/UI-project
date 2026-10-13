<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-line due date chosen by the admin when approving a request
     * (roadmap #8). NULL = fall back to the item's / category's default
     * return period.
     */
    public function up(): void
    {
        if (Schema::hasColumn('requestitem', 'due_date')) {
            return;
        }

        Schema::table('requestitem', function (Blueprint $table) {
            $table->date('due_date')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('requestitem', 'due_date')) {
            return;
        }

        Schema::table('requestitem', function (Blueprint $table) {
            $table->dropColumn('due_date');
        });
    }
};
