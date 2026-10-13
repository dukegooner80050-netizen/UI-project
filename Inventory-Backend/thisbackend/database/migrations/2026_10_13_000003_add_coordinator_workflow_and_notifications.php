<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request', function (Blueprint $table) {
            if (!Schema::hasColumn('request', 'coordinator_forwarded_by')) {
                $table->unsignedBigInteger('coordinator_forwarded_by')->nullable();
            }
            if (!Schema::hasColumn('request', 'coordinator_forwarded_at')) {
                $table->dateTime('coordinator_forwarded_at')->nullable();
            }
            if (!Schema::hasColumn('request', 'notified_at')) {
                $table->dateTime('notified_at')->nullable();
            }
            if (!Schema::hasColumn('request', 'notified_by')) {
                $table->unsignedBigInteger('notified_by')->nullable();
            }
        });

        if (!Schema::hasTable('request_notifications')) {
            // The foreign key column must match request.idrequest exactly:
            // it is a signed int(11) in some databases and unsigned in
            // others. A mismatch is what caused MySQL error 1005 / errno 150.
            $unsigned = true;
            if (DB::getDriverName() === 'mysql') {
                $col = DB::selectOne(
                    "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'request' AND COLUMN_NAME = 'idrequest'"
                );
                $unsigned = $col ? str_contains(strtolower($col->t), 'unsigned') : true;
            }

            Schema::create('request_notifications', function (Blueprint $table) use ($unsigned) {
                $table->bigIncrements('id');
                $unsigned
                    ? $table->unsignedInteger('idrequest')
                    : $table->integer('idrequest');
                $table->unsignedBigInteger('recipient_idUsers');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('event_type', 50);
                $table->string('message', 500);
                $table->dateTime('read_at')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->foreign('idrequest')->references('idrequest')->on('request')->onDelete('cascade');
                $table->foreign('recipient_idUsers')->references('idUsers')->on('users')->onDelete('cascade');
                $table->foreign('created_by')->references('idUsers')->on('users')->nullOnDelete();
                $table->index(['recipient_idUsers', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('request_notifications');
        Schema::table('request', function (Blueprint $table) {
            foreach (['coordinator_forwarded_by', 'coordinator_forwarded_at', 'notified_at', 'notified_by'] as $column) {
                if (Schema::hasColumn('request', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
