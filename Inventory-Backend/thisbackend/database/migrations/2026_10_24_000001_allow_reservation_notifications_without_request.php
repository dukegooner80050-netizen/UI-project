<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Uniform-reservation notices are not tied to any request.
    public function up(): void
    {
        if (Schema::hasTable('request_notifications')) {
            DB::statement('ALTER TABLE request_notifications MODIFY idrequest INT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        // Left nullable: rows without a request may exist.
    }
};
