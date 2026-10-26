<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request', function (Blueprint $table) {
            $table->string('released_to_student', 255)->nullable()->after('released_by');
        });
    }

    public function down(): void
    {
        Schema::table('request', function (Blueprint $table) {
            $table->dropColumn('released_to_student');
        });
    }
};
