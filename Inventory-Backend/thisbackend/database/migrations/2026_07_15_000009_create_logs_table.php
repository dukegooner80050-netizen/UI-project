<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs', function (Blueprint $table) {
            $table->increments('idlogs');
            $table->unsignedBigInteger('idUsers');
            $table->text('activity_description')->nullable();
            $table->dateTime('timestamp')->useCurrent();

            $table->foreign('idUsers')->references('idUsers')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs');
    }
};
