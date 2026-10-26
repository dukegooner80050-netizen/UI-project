<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Department;
use App\Models\Item;
use App\Models\UniformType;
use App\Models\UniformVariant;
use App\Models\Request;
use App\Models\Log;
use App\Support\ReturnPolicy;

class DashboardController extends Controller
{
    public function index()
    {
        // Stock levels are derived from each item's own thresholds
        // (Item::stock_level), so they can't be counted with a SQL where().
        $levels = Item::all()->countBy('stock_level');

        // Borrowed lines still out past their due date (roadmap #8).
        $approved = Request::where('status', 'Approved')
            ->with('items.item')
            ->get();

        // One query for every line's "waiting to be checked" quantity instead
        // of one per line.
        ReturnPolicy::preloadPending($approved->flatMap(fn ($r) => $r->items->pluck('idrequestItem')));
        app()->terminating(fn () => ReturnPolicy::forgetPending());

        $overdue = $approved
            ->sum(fn ($request) => $request->items
                ->filter(fn ($line) => ReturnPolicy::dueInfo($request, $line)['overdue'])
                ->count());

        return response()->json([

            "cards" => [

                "users" => User::count(),

                "departments" => Department::count(),

                "items" => Item::count(),

                "uniform_types" => UniformType::count(),

                "uniform_variants" => UniformVariant::count(),
            ],

            "requests" => [

                // Everything still waiting on a decision (legacy Pending + the review workflow).
                "pending" => Request::whereIn("status", ["Pending", "With Coordinator", "With Inventory"])->count(),

                // Waiting on Inventory Management specifically.
                "with_inventory" => Request::whereIn("status", ["Pending", "With Inventory"])->count(),

                "approved" => Request::where("status", "Approved")->count(),

                "overdue_returns" => $overdue,

                "rejected" => Request::where("status", "Rejected")->count(),
            ],

            "inventory" => [

                "available" => Item::where("status", "Available")->count(),

                "critical_stock" => $levels->get(Item::LEVEL_CRITICAL, 0),

                "minimum_stock" => $levels->get(Item::LEVEL_MINIMUM, 0),

                // Critical + Minimum, same meaning the old key always had.
                "low_stock" => $levels->get(Item::LEVEL_CRITICAL, 0)
                    + $levels->get(Item::LEVEL_MINIMUM, 0),

                "out_of_stock" => $levels->get(Item::LEVEL_OUT, 0),
            ],

            "recent_requests" => Request::with('user')
                ->latest('request_date')
                ->take(5)
                ->get(),

            "recent_logs" => Log::with('user')
                ->latest('timestamp')
                ->take(10)
                ->get(),

        ]);
    }
}