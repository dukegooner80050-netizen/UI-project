<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_equipment', function (Blueprint $table) {
            $table->increments('idroomequipment');
            $table->unsignedInteger('idroom');
            $table->unsignedInteger('iditems');
            $table->integer('quantity');

            $table->foreign('idroom')->references('idroom')->on('rooms');
            $table->foreign('iditems')->references('iditems')->on('items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_equipment');
    }
};
