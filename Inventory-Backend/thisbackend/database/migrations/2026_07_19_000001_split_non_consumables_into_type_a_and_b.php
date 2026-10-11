<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Office Supplies "Non-Consumable" is split in two:
     *   Non-Consumable Type A - issued, never returned
     *   Non-Consumable Type B - tracked, must be returned
     *
     * Every existing Non-Consumable becomes Type B, because that is exactly
     * how they already behaved (borrowed and returned). Change individual
     * items to Type A from the Edit dialog.
     */
    public function up(): void
    {
        DB::table('items')
            ->where('category', 'Office Supplies')
            ->where('item_type', 'Non-Consumable')
            ->update(['item_type' => 'Non-Consumable Type B']);
    }

    public function down(): void
    {
        DB::table('items')
            ->where('category', 'Office Supplies')
            ->whereIn('item_type', ['Non-Consumable Type A', 'Non-Consumable Type B'])
            ->update(['item_type' => 'Non-Consumable']);
    }
};
