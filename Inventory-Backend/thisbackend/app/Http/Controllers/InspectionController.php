<?php

namespace App\Http\Controllers;

use App\Models\PendingInspection;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Traits\ActivityLogger;

class InspectionController extends Controller
{
    use ActivityLogger;

    public function index()
    {
        $inspections = PendingInspection::with([
            'item',
            'requestItem.request',
        ])
            ->where('status', 'Pending')
            ->orderBy('returned_at', 'asc')
            ->get()
            ->map(function ($i) {
                $request = optional($i->requestItem)->request;

                return [
                    'idinspection' => $i->idinspection,
                    'item_name' => optional($i->item)->item_name,
                    'quantity' => $i->quantity,
                    'returned_at' => $i->returned_at,
                    'requestId' => optional($request)->idrequest,
                    'location' => optional($request)->location,
                    'room' => optional($request)->room,
                ];
            });

        return response()->json($inspections);
    }

    public function inspect(Request $request, $id)
    {
        $inspection = PendingInspection::find($id);

        if (!$inspection) {
            return response()->json([
                'message' => 'Inspection record not found.'
            ], 404);
        }

        if ($inspection->status !== 'Pending') {
            return response()->json([
                'message' => 'This item has already been inspected.'
            ], 400);
        }

        $validated = $request->validate([
            'fit_for_use' => 'required|integer|min:0',
            'need_maintenance' => 'required|integer|min:0',
            'disposal' => 'required|integer|min:0',
        ]);

        $total = $validated['fit_for_use'] + $validated['need_maintenance'] + $validated['disposal'];

        if ($total !== (int) $inspection->quantity) {
            return response()->json([
                'message' => "The quantities must add up to exactly {$inspection->quantity} (the amount returned)."
            ], 400);
        }

        return DB::transaction(function () use ($validated, $inspection) {

            $item = Item::findOrFail($inspection->iditems);

            if ($validated['fit_for_use'] > 0) {
                $item->quantity += $validated['fit_for_use'];
            }

            if ($validated['need_maintenance'] > 0) {
                $item->maintenance_quantity += $validated['need_maintenance'];
            }

            if ($validated['disposal'] > 0) {
                $item->damaged_quantity += $validated['disposal'];
            }

            $this->updateItemStatus($item);
            $item->save();

            $parts = [];
            if ($validated['fit_for_use'] > 0) {
                $parts[] = "{$validated['fit_for_use']} Fit for Use";
            }
            if ($validated['need_maintenance'] > 0) {
                $parts[] = "{$validated['need_maintenance']} Need Maintenance";
            }
            if ($validated['disposal'] > 0) {
                $parts[] = "{$validated['disposal']} Disposal";
            }
            $summary = implode(', ', $parts);

            $this->logActivity(
                request()->user()->idUsers,
                "Inspected '{$item->item_name}' x{$inspection->quantity} - {$summary}"
            );

            $inspection->fit_for_use_qty = $validated['fit_for_use'];
            $inspection->maintenance_qty = $validated['need_maintenance'];
            $inspection->disposal_qty = $validated['disposal'];
            $inspection->status = 'Evaluated';
            $inspection->inspected_by = request()->user()->idUsers;
            $inspection->inspected_at = now();
            $inspection->save();

            return response()->json([
                'message' => "Inspection completed: {$summary}.",
            ]);
        });
    }

    // Move quantity that was under maintenance back into available stock
    // once it's been fixed.
    public function returnToService(Request $request, $itemId)
    {
        $item = Item::find($itemId);

        if (!$item) {
            return response()->json([
                'message' => 'Item not found.'
            ], 404);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validated['quantity'] > $item->maintenance_quantity) {
            return response()->json([
                'message' => "Only {$item->maintenance_quantity} unit(s) of '{$item->item_name}' are currently under maintenance."
            ], 400);
        }

        return DB::transaction(function () use ($validated, $item) {
            $item->maintenance_quantity -= $validated['quantity'];
            $item->quantity += $validated['quantity'];
            $this->updateItemStatus($item);
            $item->save();

            $this->logActivity(
                request()->user()->idUsers,
                "Returned {$validated['quantity']} '{$item->item_name}' to service from maintenance"
            );

            return response()->json([
                'message' => 'Item returned to service successfully.',
                'item' => $item,
            ]);
        });
    }

    private function updateItemStatus(Item $item)
    {
        if ($item->quantity <= 0) {
            $item->status = "Borrowed";
        } else {
            $item->status = "Available";
        }
    }
}
