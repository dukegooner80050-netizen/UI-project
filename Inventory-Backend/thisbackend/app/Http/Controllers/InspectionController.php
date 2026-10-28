<?php

namespace App\Http\Controllers;

use App\Models\PendingInspection;
use App\Models\EquipmentReport;
use App\Models\MaintenanceRecord;
use App\Models\Room;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Traits\ActivityLogger;

class InspectionController extends Controller
{
    use ActivityLogger;

    public function index()
    {
        $pending = PendingInspection::with([
            'item',
            'requestItem.request',
        ])
            ->where('status', 'Pending')
            ->orderBy('returned_at', 'asc')
            ->get();

        // Equipment reports that sent these units here (roadmap #13).
        $reports = EquipmentReport::with('reporter')
            ->whereIn('idinspection', $pending->pluck('idinspection'))
            ->get()
            ->keyBy('idinspection');

        $inspections = $pending
            ->map(function ($i) use ($reports) {
                $request = optional($i->requestItem)->request;
                $report = $reports->get($i->idinspection);

                // Returned from a borrow request, or taken out of a room?
                $fromRoom = $i->idrequestItem === null;

                return [
                    'idinspection' => $i->idinspection,
                    'item_name' => optional($i->item)->item_name,
                    'quantity' => $i->quantity,
                    'returned_at' => $i->returned_at,
                    'source' => $fromRoom ? 'Room' : 'Request',
                    'requestId' => optional($request)->idrequest,
                    'location' => $fromRoom ? $i->source_building : optional($request)->location,
                    'room' => $fromRoom ? $i->source_room : optional($request)->room,
                    'report' => $report ? [
                        'type' => $report->report_type,
                        'description' => $report->description,
                        'reported_by' => optional($report->reporter)->full_name,
                    ] : null,
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
                $this->recordMaintenance($inspection, $item, $validated['need_maintenance']);
            }

            if ($validated['disposal'] > 0) {
                $item->damaged_quantity += $validated['disposal'];
            }

            $item->refreshStatus();
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

    // Roadmap: maintenance records. Each batch sent for repair keeps the
    // problem that was reported and the room it came from, so repaired units
    // can go back there later (see MaintenanceController).
    private function recordMaintenance(PendingInspection $inspection, Item $item, int $quantity): void
    {
        $report = EquipmentReport::where('idinspection', $inspection->idinspection)->first();
        $fromRoom = $inspection->idrequestItem === null;

        $building = $report->building_name ?? ($fromRoom ? $inspection->source_building : null);
        $roomName = $report->room_name ?? ($fromRoom ? $inspection->source_room : null);

        // Which room do repaired units go back to? Prefer the report's room;
        // otherwise match by building + room name, only when it's unambiguous.
        $idroom = null;

        if ($report && $report->idroom && Room::find($report->idroom)) {
            $idroom = $report->idroom;
        } elseif ($fromRoom && $roomName) {
            $rooms = Room::where('room_name', $roomName)
                ->when($building, fn ($q) => $q->whereHas(
                    'building',
                    fn ($b) => $b->where('building_name', $building)
                ))
                ->get();

            if ($rooms->count() === 1) {
                $idroom = $rooms->first()->idroom;
            }
        }

        if ($fromRoom && $roomName) {
            $origin = trim(($building ? $building . ' / ' : '') . $roomName);
            $source = 'Room';
        } else {
            $requestId = optional($inspection->requestItem)->idrequest;
            $origin = $requestId ? "Returned from request #{$requestId}" : 'Returned item';
            $source = 'Request';
        }

        $user = request()->user();

        MaintenanceRecord::create([
            'iditems' => $item->iditems,
            'item_name' => $item->item_name,
            'category' => $item->category,
            'idinspection' => $inspection->idinspection,
            'source' => $source,
            'origin' => $origin,
            'idroom' => $idroom,
            'building_name' => $building,
            'room_name' => $roomName,
            'problem' => optional($report)->description,
            'quantity' => $quantity,
            'status' => 'In repair',
            'sent_at' => now(),
            'sent_by_name' => $user->full_name ?? $user->username,
        ]);
    }

}
