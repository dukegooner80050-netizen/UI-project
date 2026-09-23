<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uniformvariant', function (Blueprint $table) {
            $table->increments('idUnifvariant');
            $table->unsignedInteger('idUniftype');
            $table->unsignedInteger('iddept');
            $table->string('size', 20)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->integer('quantity')->default(0);

            $table->foreign('idUniftype')->references('idUniftype')->on('uniformtype');
            $table->foreign('iddept')->references('iddept')->on('dept');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uniformvariant');
    }
};
