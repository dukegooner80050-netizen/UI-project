<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->increments('iditems');
            $table->string('item_name', 255);
            $table->string('description', 255)->nullable();
            $table->decimal('price', 10, 2)->default(0.00);
            $table->string('category', 100);
            $table->string('item_type', 100);
            $table->integer('quantity')->default(0);
            $table->string('status', 50);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
