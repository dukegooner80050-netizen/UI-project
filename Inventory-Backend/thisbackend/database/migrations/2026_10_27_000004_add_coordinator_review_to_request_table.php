<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request', function (Blueprint $table) {
            if (!Schema::hasColumn('request', 'coord_review_status')) {
                $table->string('coord_review_status', 20)->nullable(); // Acknowledged | Flagged
            }
            if (!Schema::hasColumn('request', 'coord_reviewed_by')) {
                $table->unsignedBigInteger('coord_reviewed_by')->nullable();
            }
            if (!Schema::hasColumn('request', 'coord_reviewed_at')) {
                $table->dateTime('coord_reviewed_at')->nullable();
            }
            if (!Schema::hasColumn('request', 'coord_review_note')) {
                $table->string('coord_review_note', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('request', function (Blueprint $table) {
            foreach (['coord_review_status', 'coord_reviewed_by', 'coord_reviewed_at', 'coord_review_note'] as $c) {
                if (Schema::hasColumn('request', $c)) $table->dropColumn($c);
            }
        });
    }
};
