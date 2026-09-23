<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->increments('idtransactions');
            $table->unsignedBigInteger('idUsers');
            $table->string('transaction_type', 100)->nullable();
            $table->dateTime('transaction_date')->useCurrent();

            $table->foreign('idUsers')->references('idUsers')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
