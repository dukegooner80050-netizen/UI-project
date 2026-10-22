<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request', function (Blueprint $table) {
            if (!Schema::hasColumn('request', 'student_name')) {
                $table->string('student_name', 255)->nullable()->after('purpose');
            }
            if (!Schema::hasColumn('request', 'student_number')) {
                $table->string('student_number', 50)->nullable()->after('student_name');
            }
            if (!Schema::hasColumn('request', 'released_student_id')) {
                $table->string('released_student_id', 50)->nullable()->after('released_to_student');
            }
        });
    }

    public function down(): void
    {
        Schema::table('request', function (Blueprint $table) {
            foreach (['student_name', 'student_number', 'released_student_id'] as $c) {
                if (Schema::hasColumn('request', $c)) $table->dropColumn($c);
            }
        });
    }
};
