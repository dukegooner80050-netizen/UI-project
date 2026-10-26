<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Proof of receipt: the requester confirms in the app that they got the
// items after the AMO released them. Stored as a timestamp.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('request', 'received_at')) {
            Schema::table('request', function (Blueprint $table) {
                $table->dateTime('received_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('request', 'received_at')) {
            Schema::table('request', function (Blueprint $table) {
                $table->dropColumn('received_at');
            });
        }
    }
};
