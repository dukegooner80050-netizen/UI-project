<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Request as InventoryRequest;
use App\Models\Log;
use App\Models\UniformVariant;

class ReportController extends Controller
{
    public function inventory()
    {
        return response()->json([
            'items' => Item::all(),
            'uniform_variants' => UniformVariant::with(['department','type'])->get(),
        ]);
    }

    public function requests()
    {
        return response()->json(
            InventoryRequest::with([
                'user',
                'items.item',
                'uniforms.uniformVariant.type',
                'uniforms.uniformVariant.department'
            ])->get()
        );
    }

    public function logs()
    {
        return response()->json(
            Log::with('user')
                ->orderByDesc('timestamp')
                ->get()
        );
    }
}