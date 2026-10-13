<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Items taken out of a room are not tied to a borrow request, so
        // idrequestItem has to be optional. (Raw SQL keeps the foreign key
        // as it is and does not depend on doctrine/dbal.)
        DB::statement('ALTER TABLE pending_inspections MODIFY idrequestItem INT NULL');

        // Remember where the item came from. Stored as plain text so the
        // record still reads correctly if the room or building is deleted later.
        Schema::table('pending_inspections', function (Blueprint $table) {
            $table->string('source_building', 100)->nullable()->after('iditems');
            $table->string('source_room', 100)->nullable()->after('source_building');
        });
    }

    public function down(): void
    {
        Schema::table('pending_inspections', function (Blueprint $table) {
            $table->dropColumn(['source_building', 'source_room']);
        });

        // Room returns have no request item, so they cannot exist in the old shape.
        DB::table('pending_inspections')->whereNull('idrequestItem')->delete();
        DB::statement('ALTER TABLE pending_inspections MODIFY idrequestItem INT NOT NULL');
    }
};
