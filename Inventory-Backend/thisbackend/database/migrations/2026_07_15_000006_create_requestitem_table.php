<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requestitem', function (Blueprint $table) {
            $table->increments('idrequestItem');
            $table->unsignedInteger('idrequest');
            $table->unsignedInteger('iditems');
            $table->integer('quantity');
            $table->integer('returned_quantity')->default(0);

            $table->foreign('idrequest')->references('idrequest')->on('request');
            $table->foreign('iditems')->references('iditems')->on('items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requestitem');
    }
};
