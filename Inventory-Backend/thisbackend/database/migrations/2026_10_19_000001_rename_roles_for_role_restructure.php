<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Roadmap #18: role restructure.
//   'admin' (old meaning: full inventory management) -> 'amo'
//   'dean'                                            -> 'department_head'
//   'cashier'                                          -> unchanged
// 'admin' is reused with a NEW meaning (ordinary requester, office staff)
// and 'instructor' is a brand new role -- neither has existing rows to
// migrate, so there is nothing to do for them here.
//
// AMO is a SINGLETON (confirmed by the client): only ONE 'admin' row can
// become 'amo'. FIXED 2026-10-19 -- this used to promote every 'admin'
// row to 'amo', which would have silently created two AMO accounts on a
// DB with more than one (the live DB had exactly 2). Now only the
// oldest admin account (lowest idUsers -- the earliest-created one) is
// promoted; any other 'admin' rows are left as 'admin', which is now
// the ordinary-requester role under the new permission model, not a
// loss of a real capability they had before (only the single AMO kept
// inventory-management power either way). The client picks which
// account should actually be AMO afterward, through Account
// Management, same as confirmed -- this just avoids leaving the system
// in a broken two-AMO state in the meantime.
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
