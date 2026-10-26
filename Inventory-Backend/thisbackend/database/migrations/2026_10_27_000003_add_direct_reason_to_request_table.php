<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('request', 'direct_to_amo_reason')) {
            Schema::table('request', function (Blueprint $table) {
                // Set when an instructor skipped the department head.
                $table->string('direct_to_amo_reason', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('request', 'direct_to_amo_reason')) {
            Schema::table('request', fn (Blueprint $t) => $t->dropColumn('direct_to_amo_reason'));
        }
    }
};
