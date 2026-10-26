<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permanent record of every disposal. Units flagged for disposal at
     * inspection sit in items.damaged_quantity; disposing them removes them
     * from that count and writes a row here.
     *
     * The item name / category and the user's name are copied in as text and
     * there is deliberately NO foreign key, so a record still reads correctly
     * (and is never cascaded away) if the item or the user is deleted later.
     */
    public function up(): void
    {
        if (Schema::hasTable('disposal_records')) {
            return;
        }

        Schema::create('disposal_records', function (Blueprint $table) {
            $table->increments('iddisposal');
            $table->unsignedInteger('iditems')->nullable();
            $table->string('item_name', 150);
            $table->string('category', 50)->nullable();
            $table->string('item_type', 60)->nullable();
            $table->unsignedInteger('quantity');
            $table->string('method', 30);                       // Discarded | Sold as scrap | Donated | Recycled | Other
            $table->string('notes', 255)->nullable();
            $table->date('disposed_on');
            $table->unsignedBigInteger('disposed_by')->nullable();
            $table->string('disposed_by_name', 100)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index('disposed_on');
        });
    }

    public function down(): void
    {
        // Disposal records are an audit trail: never drop them on rollback.
    }
};
