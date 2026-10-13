<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roadmap #13: reports that school equipment (e.g. an aircon in a room)
     * needs maintenance or disposal. Status flow:
     *   Open -> Sent to Inspection   (admin: units leave the room and wait in
     *                                 Pending Inspection)
     *   Open -> Dismissed            (admin: no action needed, reason stored)
     * Building / room names are copied in as text so a report still reads
     * correctly if the room is later renamed or deleted.
     */
    public function up(): void
    {
        if (Schema::hasTable('equipment_reports')) {
            return;
        }

        // The foreign key column must match items.iditems exactly
        // (signed int(11) in some databases, unsigned in others).
        $unsigned = true;
        if (DB::getDriverName() === 'mysql') {
            $col = DB::selectOne(
                "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'items' AND COLUMN_NAME = 'iditems'"
            );
            $unsigned = $col ? str_contains(strtolower($col->t), 'unsigned') : true;
        }

        Schema::create('equipment_reports', function (Blueprint $table) use ($unsigned) {
            $table->increments('idreport');
            $unsigned
                ? $table->unsignedInteger('iditems')
                : $table->integer('iditems');
            $table->unsignedInteger('idroom')->nullable();
            $table->string('building_name', 100)->nullable();
            $table->string('room_name', 100)->nullable();
            $table->unsignedInteger('quantity');
            $table->string('report_type', 20);              // Maintenance | Disposal
            $table->string('description', 500);
            $table->string('status', 30)->default('Open'); // Open | Sent to Inspection | Dismissed
            $table->unsignedBigInteger('reported_by');
            $table->dateTime('created_at')->useCurrent();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_notes', 255)->nullable();
            $table->unsignedInteger('idinspection')->nullable();

            $table->foreign('iditems')->references('iditems')->on('items');
            $table->foreign('reported_by')->references('idUsers')->on('users');
            $table->foreign('reviewed_by')->references('idUsers')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_reports');
    }
};
