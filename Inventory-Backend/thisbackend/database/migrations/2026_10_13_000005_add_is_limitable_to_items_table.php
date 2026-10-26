<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roadmap #10: School Equipment split - separate equipment that CAN be
     * consumable/limited from equipment that CANNOT have a limit (e.g.
     * fixed assets like projectors vs. consumable accessories).
     *
     * is_limitable = false marks a "fixed asset" item: unique/large-ticket
     * equipment (a single room's projector, an aircon) where a per-head
     * limit (roadmap #9) doesn't make sense. Defaults to true so every
     * existing item keeps behaving as it does today; an admin opts specific
     * School Equipment items out from the Edit screen.
     */
    public function up(): void
    {
        if (Schema::hasColumn('items', 'is_limitable')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_limitable')->default(true)->after('per_head_limit');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('items', 'is_limitable')) {
            return;
        }

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('is_limitable');
        });
    }
};
