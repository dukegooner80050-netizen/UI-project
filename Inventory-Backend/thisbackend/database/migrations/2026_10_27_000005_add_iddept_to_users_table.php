<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'iddept')) {
            Schema::table('users', function (Blueprint $table) {
                // Department of an instructor / department head (set by the AMO).
                $table->unsignedBigInteger('iddept')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'iddept')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('iddept'));
        }
    }
};
