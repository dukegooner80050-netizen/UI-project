<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per batch of units sent for repair (created when inspection
     * marks units "Need Maintenance"). items.maintenance_quantity stays as the
     * running total; these rows say why, where from, since when, and how each
     * batch ended (repaired / can't be repaired).
     *
     * No foreign keys on purpose: item and room details are copied in as text
     * so the history stays readable if an item or room is deleted later.
     */
    public function up(): void
    {
        if (Schema::hasTable('maintenance_records')) {
            return;
        }

        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->increments('idmaintenance');
            $table->unsignedInteger('iditems')->nullable();
            $table->string('item_name', 150);
            $table->string('category', 50)->nullable();
            $table->unsignedInteger('idinspection')->nullable();
            $table->string('source', 20)->default('Room');     // Room | Request | Legacy
            $table->string('origin', 150)->nullable();         // e.g. "SFB.Building 1 / Room 201"
            $table->unsignedInteger('idroom')->nullable();     // where repaired units go back to
            $table->string('building_name', 100)->nullable();
            $table->string('room_name', 100)->nullable();
            $table->string('problem', 500)->nullable();        // what was reported
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('repaired_qty')->default(0);
            $table->unsignedInteger('unrepairable_qty')->default(0);
            $table->string('status', 20)->default('In repair'); // In repair | Closed
            $table->dateTime('sent_at')->useCurrent();
            $table->string('sent_by_name', 100)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->string('closed_by_name', 100)->nullable();
            $table->text('resolution_notes')->nullable();

            $table->index('status');
            $table->index('iditems');
        });

        // Units already under maintenance before this feature have no history.
        // Give each item's existing count a "Before records" row so they can
        // be resolved like everything else.
        DB::table('items')
            ->where('maintenance_quantity', '>', 0)
            ->get(['iditems', 'item_name', 'category', 'maintenance_quantity'])
            ->each(function ($item) {
                DB::table('maintenance_records')->insert([
                    'iditems' => $item->iditems,
                    'item_name' => $item->item_name,
                    'category' => $item->category,
                    'source' => 'Legacy',
                    'origin' => 'Before records',
                    'quantity' => $item->maintenance_quantity,
                    'status' => 'In repair',
                    'sent_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Maintenance records are an audit trail: never drop them on rollback.
    }
};
