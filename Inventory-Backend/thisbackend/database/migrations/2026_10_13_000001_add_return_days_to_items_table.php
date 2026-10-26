<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Borrowed item return duration (roadmap #8).
     *
     * items.return_days = how many days a borrower may keep one of this item
     * before it counts as overdue. NULL means "use the category default"
     * (Settings page), and if that is not set either, no due date applies.
     */
    public function up(): void
    {
        if (Schema::hasColumn('items', 'return_days')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            $table->unsignedInteger('return_days')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('items', 'return_days')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('return_days');
        });
    }
};
