<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formal record of stock shrinkage (missing / lost / stolen items).
     * `status` is 'Applied' for now (admin-only, immediate); it leaves room
     * for an approval step later without changing this table.
     */
    public function up(): void
    {
        // A previous run failed at the foreign key step, but MySQL does not
        // roll back CREATE TABLE, so a half-built empty table may be left.
        Schema::dropIfExists('stock_adjustments');

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

        Schema::create('stock_adjustments', function (Blueprint $table) use ($unsigned) {
            $table->increments('idstockadj');
            $unsigned
                ? $table->unsignedInteger('iditems')
                : $table->integer('iditems');
            $table->unsignedBigInteger('idUsers');
            $table->unsignedInteger('quantity');
            $table->integer('previous_quantity');
            $table->integer('new_quantity');
            $table->string('reason', 50);
            $table->string('notes', 255)->nullable();
            $table->string('status', 20)->default('Applied');
            $table->dateTime('created_at')->useCurrent();

            $table->foreign('iditems')->references('iditems')->on('items');
            $table->foreign('idUsers')->references('idUsers')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};