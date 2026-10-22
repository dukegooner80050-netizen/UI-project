<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('uniformvariant', 'gender')) {
            Schema::table('uniformvariant', function (Blueprint $table) {
                $table->string('gender', 20)->default('Unisex')->after('size');
            });
        }

        if (!Schema::hasTable('uniform_reservations')) {
            Schema::create('uniform_reservations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('idUsers');
                $table->unsignedInteger('idUnifvariant');
                $table->unsignedInteger('quantity');
                $table->string('status', 20)->default('Waiting');
                $table->dateTime('notified_at')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->nullable();
                $table->foreign('idUsers')->references('idUsers')->on('users')->onDelete('cascade');
                $table->foreign('idUnifvariant')->references('idUnifvariant')->on('uniformvariant')->onDelete('cascade');
                $table->index(['idUnifvariant', 'status', 'created_at']);
                $table->index(['idUsers', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Keep uniform_reservations: it may predate this migration and contain live reservation data.
        if (Schema::hasColumn('uniformvariant', 'gender')) {
            Schema::table('uniformvariant', function (Blueprint $table) {
                $table->dropColumn('gender');
            });
        }
    }
};
