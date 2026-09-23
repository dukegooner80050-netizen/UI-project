<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requestuniform', function (Blueprint $table) {
            $table->increments('idrequestUniform');
            $table->unsignedInteger('idrequest');
            $table->unsignedInteger('idUnifvariant');
            $table->integer('quantity');

            $table->foreign('idrequest')->references('idrequest')->on('request');
            $table->foreign('idUnifvariant')->references('idUnifvariant')->on('uniformvariant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requestuniform');
    }
};
