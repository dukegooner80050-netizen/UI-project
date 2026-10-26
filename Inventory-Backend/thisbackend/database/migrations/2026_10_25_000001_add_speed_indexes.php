<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the columns the app filters and sorts on all the time but
     * that have none (foreign keys already get one from MySQL):
     *
     *   logs.timestamp   - Active Logs sorts and date-filters on it, and the
     *                      table only ever grows
     *   request.status   - nearly every screen asks "which requests are
     *                      Pending / Approved / ..."
     *
     * Safe to run twice or on a database that already has them: a duplicate
     * index is simply skipped.
     */
    public function up(): void
    {
        $this->addIndex('logs', 'timestamp', 'logs_timestamp_index');
        $this->addIndex('request', 'status', 'request_status_index');
    }

    public function down(): void
    {
        $this->dropIndex('logs', 'logs_timestamp_index');
        $this->dropIndex('request', 'request_status_index');
    }

    private function addIndex(string $table, string $column, string $name): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $t) use ($column, $name) {
                $t->index($column, $name);
            });
        } catch (\Throwable $e) {
            // already there
        }
    }

    private function dropIndex(string $table, string $name): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($name) {
                $t->dropIndex($name);
            });
        } catch (\Throwable $e) {
            // not there
        }
    }
};
