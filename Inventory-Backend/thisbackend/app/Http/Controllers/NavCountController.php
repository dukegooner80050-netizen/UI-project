<?php

namespace App\Http\Controllers;

use App\Models\EquipmentReport;
use App\Models\PendingInspection;
use App\Models\Request;
use App\Models\User;

// The AMO's sidebar badges. Four cheap COUNT queries in one request,
// instead of downloading whole lists (requests, inspections, accounts,
// equipment reports) every couple of seconds just to count them.
class NavCountController extends Controller
{
    public function index()
    {
        return response()->json([
            'pending_requests' => Request::whereIn('status', ['With Inventory', 'Pending'])->count(),
            'pending_inspections' => PendingInspection::where('status', 'Pending')->count(),
            'account_requests' => User::where('status', 'pending')->count(),
            'open_equipment_reports' => EquipmentReport::where('status', 'Open')->count(),
        ]);
    }
}
