<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional note the approver can attach when approving a request
// (e.g. "Size L available after restock", "Pick up at the AMO office").
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('request', 'approval_remarks')) {
            Schema::table('request', function (Blueprint $table) {
                $table->string('approval_remarks', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('request', 'approval_remarks')) {
            Schema::table('request', function (Blueprint $table) {
                $table->dropColumn('approval_remarks');
            });
        }
    }
};
