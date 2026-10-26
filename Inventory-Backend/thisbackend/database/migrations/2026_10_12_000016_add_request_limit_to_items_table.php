<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * request_limit: max quantity of this item a requester may ask for in
     * a single request. NULL = no limit (e.g. low-value items such as
     * monoblock chairs). Only meaningful for School Equipment.
     */
    public function up(): void
    {
        if (Schema::hasColumn('items', 'request_limit')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            $table->unsignedInteger('request_limit')->nullable()->after('damaged_quantity');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('items', 'request_limit')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('request_limit');
        });
    }
};
