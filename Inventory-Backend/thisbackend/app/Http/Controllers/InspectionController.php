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
            'outcome' => 'required|in:Good,Damaged',
        ]);

        return DB::transaction(function () use ($validated, $inspection) {

            $item = Item::findOrFail($inspection->iditems);

            if ($validated['outcome'] === 'Good') {
                $item->quantity += $inspection->quantity;
                $this->updateItemStatus($item);
                $item->save();

                $this->logActivity(
                    request()->user()->idUsers,
                    "Inspected '{$item->item_name}' x{$inspection->quantity} - Good (restocked)"
                );
            } else {
                $item->damaged_quantity += $inspection->quantity;
                $item->save();

                $this->logActivity(
                    request()->user()->idUsers,
                    "Inspected '{$item->item_name}' x{$inspection->quantity} - Damaged (written off)"
                );
            }

            $inspection->status = $validated['outcome'];
            $inspection->inspected_by = request()->user()->idUsers;
            $inspection->inspected_at = now();
            $inspection->save();

            return response()->json([
                'message' => "Item marked as {$validated['outcome']}.",
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
