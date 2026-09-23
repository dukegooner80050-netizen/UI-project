<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_inspections', function (Blueprint $table) {
            $table->increments('idinspection');
            $table->integer('idrequestItem');
            $table->integer('iditems');
            $table->integer('quantity');
            $table->string('status', 20)->default('Pending'); // Pending | Good | Damaged
            $table->dateTime('returned_at')->useCurrent();
            $table->unsignedBigInteger('inspected_by')->nullable();
            $table->dateTime('inspected_at')->nullable();
 
            $table->foreign('idrequestItem')->references('idrequestItem')->on('requestitem');
            $table->foreign('iditems')->references('iditems')->on('items');
            $table->foreign('inspected_by')->references('idUsers')->on('users');
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('pending_inspections');
    }
};