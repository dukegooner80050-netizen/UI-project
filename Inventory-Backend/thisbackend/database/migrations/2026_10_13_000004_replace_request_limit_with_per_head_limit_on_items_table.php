<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roadmap #9 (revised): "cap how much a single requester can
     * request/hold of a given item at once ... should be recorded how
     * much a requester already request the item."
     *
     * The existing `request_limit` column was never actually usable - it
     * wasn't in Item::$fillable or ItemController's validation, so no admin
     * screen could ever set it, and the one place that read it only summed
     * quantities within a single submission (not what the requester already
     * holds/has pending). Replacing it with `per_head_limit`, which
     * RequestController now enforces against the requester's running total
     * across all their requests for that item (see perHeadUsage()).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('items', 'per_head_limit')) {
            Schema::table('items', function (Blueprint $table) {
                $table->unsignedInteger('per_head_limit')->nullable()->after('request_limit');
            });
        }

        if (Schema::hasColumn('items', 'request_limit')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('request_limit');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('items', 'request_limit')) {
            Schema::table('items', function (Blueprint $table) {
                $table->unsignedInteger('request_limit')->nullable();
            });
        }

        if (Schema::hasColumn('items', 'per_head_limit')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('per_head_limit');
            });
        }
    }
};
