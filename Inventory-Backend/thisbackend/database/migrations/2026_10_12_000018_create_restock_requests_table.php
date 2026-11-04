<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * creates restock requests, raised when an item reaches Critical / Minimum stock.
     * Status: Open -> Ordered -> Received (or Cancelled); receiving adds the stock.
     */
    public function up(): void
    {
        // Never drop an existing table: it may already hold restock history.
        if (Schema::hasTable('restock_requests')) {
            return;
        }

        /* FK column must match items.iditems exactly (signed or unsigned varies by database). */
        $unsigned = true;
        if (DB::getDriverName() === 'mysql') {
            $col = DB::selectOne(
                "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'items' AND COLUMN_NAME = 'iditems'"
            );
            $unsigned = $col ? str_contains(strtolower($col->t), 'unsigned') : true;
        }

        Schema::create('restock_requests', function (Blueprint $table) use ($unsigned) {
            $table->increments('idrestock');
            $unsigned
                ? $table->unsignedInteger('iditems')
                : $table->integer('iditems');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedInteger('quantity_needed');
            $table->unsignedInteger('quantity_received')->nullable();
            $table->string('status', 20)->default('Open');
            $table->string('notes', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('ordered_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->foreign('iditems')->references('iditems')->on('items');
            $table->foreign('requested_by')->references('idUsers')->on('users');
            $table->foreign('closed_by')->references('idUsers')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restock_requests');
    }
};
