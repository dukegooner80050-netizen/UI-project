<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('requestitem', 'received_at')) {
            Schema::table('requestitem', function (Blueprint $table) {
                // When the requester confirmed receiving this line.
                $table->dateTime('received_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('requestitem', 'received_at')) {
            Schema::table('requestitem', fn (Blueprint $t) => $t->dropColumn('received_at'));
        }
    }
};
