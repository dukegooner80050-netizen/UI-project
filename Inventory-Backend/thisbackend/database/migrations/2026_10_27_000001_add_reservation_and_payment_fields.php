<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('items', 'reserved_quantity')) {
            Schema::table('items', function (Blueprint $table) {
                $table->unsignedInteger('reserved_quantity')->default(0)->after('quantity');
            });
        }
        Schema::table('request', function (Blueprint $table) {
            if (!Schema::hasColumn('request', 'stock_held')) {
                // 1 = approved items are reserved, not yet deducted (deducted at release).
                $table->boolean('stock_held')->default(false);
            }
            if (!Schema::hasColumn('request', 'paid_at')) {
                $table->dateTime('paid_at')->nullable();
            }
            if (!Schema::hasColumn('request', 'paid_by')) {
                $table->unsignedBigInteger('paid_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('items', 'reserved_quantity')) {
            Schema::table('items', fn (Blueprint $t) => $t->dropColumn('reserved_quantity'));
        }
        Schema::table('request', function (Blueprint $table) {
            foreach (['stock_held', 'paid_at', 'paid_by'] as $c) {
                if (Schema::hasColumn('request', $c)) $table->dropColumn($c);
            }
        });
    }
};
