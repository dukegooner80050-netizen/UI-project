<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->increments('idsetting');
            $table->string('setting_key', 100)->unique();
            $table->string('setting_value', 255);
        });

        // Seed the default monthly request limit so the app has a sane
        // value from the moment this migration runs.
        \DB::table('settings')->insert([
            'setting_key' => 'monthly_request_limit',
            'setting_value' => '5',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
