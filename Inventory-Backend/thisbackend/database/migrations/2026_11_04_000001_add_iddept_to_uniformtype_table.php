<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * A uniform type now belongs to one course. A NULL iddept means the type is
     * shared by every course, e.g. the "College Polo".
     */
    public function up(): void
    {
        Schema::table('uniformtype', function (Blueprint $table) {
            // Signed int to match dept.iddept, otherwise the foreign key cannot be created.
            $table->integer('iddept')->nullable()->after('idUniftype');
            $table->foreign('iddept')->references('iddept')->on('dept');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('uniformtype', function (Blueprint $table) {
            $table->dropForeign(['iddept']);
            $table->dropColumn('iddept');
        });
    }

    /*
     * Until now a type was only linked to a course through its variants: a type
     * stocked by exactly one course becomes that course's type, while a type
     * used by several courses (or none) stays shared so no course loses access
     * to it. College-wide types stay shared on purpose.
     */
    private function backfill(): void
    {
        $shared = ['College Polo'];

        $types = DB::table('uniformtype')->select('idUniftype', 'uniform_name')->get();

        foreach ($types as $type) {
            if (in_array($type->uniform_name, $shared, true)) {
                continue;
            }

            $departments = DB::table('uniformvariant')
                ->where('idUniftype', $type->idUniftype)
                ->distinct()
                ->pluck('iddept');

            if ($departments->count() !== 1) {
                continue;
            }

            DB::table('uniformtype')
                ->where('idUniftype', $type->idUniftype)
                ->update(['iddept' => $departments->first()]);
        }
    }
};
