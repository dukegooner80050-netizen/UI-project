<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request', function (Blueprint $table) {
            $table->increments('idrequest');
            $table->unsignedBigInteger('idUsers');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('request_date')->useCurrent();
            $table->string('status', 50)->default('Pending');
            $table->date('borrowed_at')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('room', 100)->nullable();
            $table->text('purpose')->nullable();
            $table->text('rejectReason')->nullable();

            $table->foreign('idUsers')->references('idUsers')->on('users');
            $table->foreign('approved_by')->references('idUsers')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request');
    }
};
