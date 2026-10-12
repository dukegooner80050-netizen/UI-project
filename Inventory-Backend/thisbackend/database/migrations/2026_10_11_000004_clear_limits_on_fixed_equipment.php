<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing School Equipment is 'Equipment' = fixed asset, which cannot
        // have a per-requester limit. Clear any limit set before that rule.
        if (Schema::hasColumn('items', 'limit_per_requester')) {
            DB::table('items')
                ->where('category', 'School Equipment')
                ->where('item_type', '!=', 'Equipment (Limited)')
                ->whereNotNull('limit_per_requester')
                ->update(['limit_per_requester' => null]);
        }
    }

    public function down(): void
    {
        // Cleared limits cannot be restored.
    }
};
