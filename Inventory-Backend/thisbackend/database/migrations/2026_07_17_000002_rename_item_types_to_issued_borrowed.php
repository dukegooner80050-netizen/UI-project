<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Item category split (roadmap #7).
     *
     * items.item_type used to hold "Consumable" / "Non-Consumable" (office
     * supplies) or "Equipment" (school equipment). It now holds:
     *
     *   Issued   - given away permanently, never expected back
     *   Borrowed - lent out, expected to come back
     *
     * Mapping: Consumable -> Issued; Non-Consumable and Equipment -> Borrowed.
     * Status is then recomputed for Issued items, because their "Low Stock"
     * cut-off now comes from minimum_stock instead of a hard-coded 5.
     */
    public function up(): void
    {
        DB::table('items')->where('item_type', 'Consumable')->update(['item_type' => 'Issued']);
        DB::table('items')->whereIn('item_type', ['Non-Consumable', 'Equipment'])->update(['item_type' => 'Borrowed']);

        DB::table('items')->where('item_type', 'Issued')->get()->each(function ($item) {
            if ($item->quantity <= 0) {
                $status = 'Out of Stock';
            } elseif ($item->quantity <= $item->minimum_stock) {
                $status = 'Low Stock';
            } else {
                $status = 'Available';
            }

            DB::table('items')->where('iditems', $item->iditems)->update(['status' => $status]);
        });
    }

    public function down(): void
    {
        // Issued only ever existed as "Consumable" (Office Supplies).
        DB::table('items')->where('item_type', 'Issued')->update(['item_type' => 'Consumable']);

        DB::table('items')->where('item_type', 'Borrowed')
            ->where('category', 'School Equipment')
            ->update(['item_type' => 'Equipment']);

        DB::table('items')->where('item_type', 'Borrowed')
            ->update(['item_type' => 'Non-Consumable']);
    }
};
