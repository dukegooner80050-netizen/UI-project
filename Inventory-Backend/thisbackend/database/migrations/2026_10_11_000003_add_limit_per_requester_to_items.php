<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Most one requester may have of this item at once. Null = no limit.
        if (!Schema::hasColumn('items', 'limit_per_requester')) {
            Schema::table('items', function (Blueprint $table) {
                $table->unsignedSmallInteger('limit_per_requester')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('items', 'limit_per_requester')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('limit_per_requester');
            });
        }
    }
};
