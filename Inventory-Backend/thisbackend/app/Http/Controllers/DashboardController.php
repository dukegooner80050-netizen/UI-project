<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Department;
use App\Models\Item;
use App\Models\UniformType;
use App\Models\UniformVariant;
use App\Models\Request;
use App\Models\Log;

class DashboardController extends Controller
{
    public function index()
    {
        return response()->json([

            "cards" => [

                "users" => User::count(),

                "departments" => Department::count(),

                "items" => Item::count(),

                "uniform_types" => UniformType::count(),

                "uniform_variants" => UniformVariant::count(),
            ],

            "requests" => [

                "pending" => Request::where("status", "Pending")->count(),

                "approved" => Request::where("status", "Approved")->count(),

                "rejected" => Request::where("status", "Rejected")->count(),
            ],

            "inventory" => [

                "available" => Item::where("status", "Available")->count(),

                "low_stock" => Item::where("status", "Low Stock")->count(),

                "out_of_stock" => Item::where("status", "Out of Stock")->count(),
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