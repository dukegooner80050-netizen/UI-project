<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Roadmap #15 / #19: once a request is Approved, the admin/AMO records
// how the items are going out the door -- "For Pick Up" or "For Delivery"
// -- rather than that just being understood informally. This does NOT
// introduce a new request status; it extends the existing "Approved"
// state with release details, so nothing that already filters/behaves
// on status === 'Approved' (overdue tracking, returns, badges) changes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request', function (Blueprint $table) {
            if (!Schema::hasColumn('request', 'release_method')) {
                // 'For Pick Up' | 'For Delivery'
                $table->string('release_method', 20)->nullable();
            }
            if (!Schema::hasColumn('request', 'released_at')) {
                $table->dateTime('released_at')->nullable();
            }
            if (!Schema::hasColumn('request', 'released_by')) {
                $table->unsignedBigInteger('released_by')->nullable();
            }
        });

        Schema::table('request', function (Blueprint $table) {
            if (!$this->hasForeign('request', 'request_released_by_foreign')) {
                $table->foreign('released_by')->references('idUsers')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('request', function (Blueprint $table) {
            if ($this->hasForeign('request', 'request_released_by_foreign')) {
                $table->dropForeign('request_released_by_foreign');
            }
            foreach (['release_method', 'released_at', 'released_by'] as $column) {
                if (Schema::hasColumn('request', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasForeign(string $table, string $name): bool
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'mysql') {
            return false;
        }

        $row = \Illuminate\Support\Facades\DB::selectOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$table, $name]
        );

        return (bool) $row;
    }
};
