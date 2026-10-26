<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Return reports: the borrower says "I returned this" and the AMO then
     * confirms or disputes it. A report is time-stamped proof that the
     * borrower brought the item back, so the borrower is not stuck as
     * "overdue" just because the AMO has not recorded the return yet.
     *
     * No foreign keys on purpose: the id column types differ between
     * databases (signed vs unsigned) and a mismatch breaks the migration.
     */
    public function up(): void
    {
        if (Schema::hasTable('return_reports')) {
            return;
        }

        Schema::create('return_reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('idrequestItem')->index();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('Pending'); // Pending | Confirmed | Rejected
            $table->string('note', 255)->nullable();          // AMO's reason when rejected
            $table->unsignedBigInteger('reported_by');
            $table->dateTime('reported_at');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();

            $table->index(['status', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_reports');
    }
};
