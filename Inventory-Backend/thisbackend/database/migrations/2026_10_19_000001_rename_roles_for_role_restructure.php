<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Role restructure: admin -> amo, dean -> department_head; only the oldest admin becomes AMO (singleton). */
return new class extends Migration
{
    public function up(): void
    {
        $firstAdminId = DB::table('users')
            ->where('role', 'admin')
            ->orderBy('idUsers')
            ->value('idUsers');

        if ($firstAdminId !== null) {
            DB::table('users')->where('idUsers', $firstAdminId)->update(['role' => 'amo']);
        }

        DB::table('users')->where('role', 'dean')->update(['role' => 'department_head']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'amo')->update(['role' => 'admin']);
        DB::table('users')->where('role', 'department_head')->update(['role' => 'dean']);
    }
};
