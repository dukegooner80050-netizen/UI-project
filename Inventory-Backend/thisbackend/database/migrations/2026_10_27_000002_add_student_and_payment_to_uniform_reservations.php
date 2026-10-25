<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uniform_reservations', function (Blueprint $table) {
            if (!Schema::hasColumn('uniform_reservations', 'student_name')) {
                $table->string('student_name', 255)->nullable();
            }
            if (!Schema::hasColumn('uniform_reservations', 'student_number')) {
                $table->string('student_number', 50)->nullable();
            }
            if (!Schema::hasColumn('uniform_reservations', 'paid_at')) {
                $table->dateTime('paid_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('uniform_reservations', function (Blueprint $table) {
            foreach (['student_name', 'student_number', 'paid_at'] as $c) {
                if (Schema::hasColumn('uniform_reservations', $c)) $table->dropColumn($c);
            }
        });
    }
};
