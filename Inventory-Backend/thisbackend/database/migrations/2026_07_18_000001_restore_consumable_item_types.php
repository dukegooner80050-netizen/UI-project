<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Issued/Borrowed split (roadmap #7) was reverted. Databases that had
     * already run the old rename migration hold "Issued" / "Borrowed" in
     * items.item_type; put the original values back. Safe to run on a
     * database that was never converted (it simply matches no rows).
     *
     *   Office Supplies:  Issued -> Consumable, Borrowed -> Non-Consumable
     *   School Equipment: Issued / Borrowed -> Equipment
     *
     * Uniform rows keep their department code in item_type and are untouched.
     */
    public function up(): void
    {
        DB::table('items')->where('category', 'Office Supplies')->where('item_type', 'Issued')->update(['item_type' => 'Consumable']);
        DB::table('items')->where('category', 'Office Supplies')->where('item_type', 'Borrowed')->update(['item_type' => 'Non-Consumable']);
        DB::table('items')->where('category', 'School Equipment')->whereIn('item_type', ['Issued', 'Borrowed'])->update(['item_type' => 'Equipment']);

        // Recompute status for the rows whose type just changed back.
        DB::table('items')->whereIn('category', ['Office Supplies', 'School Equipment'])->get()->each(function ($item) {
            if ($item->category === 'Office Supplies' && $item->item_type === 'Consumable') {
                if ($item->quantity <= 0) {
                    $status = 'Out of Stock';
                } elseif ($item->quantity <= $item->minimum_stock) {
                    $status = 'Low Stock';
                } else {
                    $status = 'Available';
                }
            } else {
                $status = $item->quantity <= 0 ? 'Borrowed' : 'Available';
            }

            if ($status !== $item->status) {
                DB::table('items')->where('iditems', $item->iditems)->update(['status' => $status]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: the Issued/Borrowed values are no longer used.
    }
};
