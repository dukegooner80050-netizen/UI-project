<?php

namespace App\Http\Controllers;

use App\Models\EquipmentReport;
use App\Models\PendingInspection;
use App\Models\RoomEquipment;
use App\Traits\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Roadmap #13: anyone can report that equipment in a room needs maintenance
// or disposal. The admin then either sends the units to Pending Inspection
// (the existing evaluation flow) or dismisses the report with a reason.
class EquipmentReportController extends Controller
{
    use ActivityLogger;

    private const TYPES = ['Maintenance', 'Disposal'];

    private function typeLabel(string $type): string
    {
        return $type === 'Disposal' ? 'For Disposal' : 'Needs Maintenance';
    }

    /**
     * Rooms that have equipment, for the report form. Includes how many units
     * of each can still be reported (assigned minus units already in an open
     * report), so nobody can report more than the room holds.
     */
    public function locations()
    {
        $open = EquipmentReport::where('status', 'Open')
            ->selectRaw('idroom, iditems, SUM(quantity) as qty')
            ->groupBy('idroom', 'iditems')
            ->get()
            ->keyBy(fn ($r) => $r->idroom . '-' . $r->iditems);

        $rooms = [];

        RoomEquipment::with(['room.building', 'item'])
            ->where('quantity', '>', 0)
            ->get()
            ->each(function ($e) use (&$rooms, $open) {
                if (!$e->room || !$e->item) {
                    return;
                }

                $reported = (int) optional($open->get($e->idroom . '-' . $e->iditems))->qty;

                $rooms[$e->idroom] ??= [
                    'idroom' => $e->idroom,
                    'room_name' => $e->room->room_name,
                    'building_name' => optional($e->room->building)->building_name,
                    'equipment' => [],
                ];

                $rooms[$e->idroom]['equipment'][] = [
                    'iditems' => $e->iditems,
                    'item_name' => $e->item->item_name,
                    'quantity' => (int) $e->quantity,
                    'already_reported' => $reported,
                    'available_to_report' => max((int) $e->quantity - $reported, 0),
                ];
            });

        $rooms = array_values($rooms);

        usort($rooms, fn ($a, $b) => strcmp(
            ($a['building_name'] ?? '') . ' ' . $a['room_name'],
            ($b['building_name'] ?? '') . ' ' . $b['room_name']
        ));

        return response()->json($rooms);
    }

    // Admins see every report; everyone else sees only their own.
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = strtolower((string) $user->role) === 'amo';

        $query = EquipmentReport::with(['item', 'reporter'])->orderByDesc('idreport');

        if (!$isAdmin) {
            $query->where('reported_by', $user->idUsers);
        }

        $reports = $query->limit(300)->get();

        // What happened to units that were sent to inspection.
        $inspections = PendingInspection::whereIn('idinspection', $reports->pluck('idinspection')->filter())
            ->get()
            ->keyBy('idinspection');

        return response()->json($reports->map(function ($r) use ($inspections) {
            $inspection = $r->idinspection ? $inspections->get($r->idinspection) : null;

            return [
                'id' => $r->idreport,
                'iditems' => $r->iditems,
                'item_name' => optional($r->item)->item_name,
                'building_name' => $r->building_name,
                'room_name' => $r->room_name,
                'quantity' => $r->quantity,
                'report_type' => $r->report_type,
                'description' => $r->description,
                'status' => $r->status,
                'reported_by' => optional($r->reporter)->full_name,
                'created_at' => $r->created_at,
                'reviewed_at' => $r->reviewed_at,
                'review_notes' => $r->review_notes,
                'inspection' => $inspection ? [
                    'status' => $inspection->status,
                    'fit_for_use' => $inspection->fit_for_use_qty,
                    'maintenance' => $inspection->maintenance_qty,
                    'disposal' => $inspection->disposal_qty,
                ] : null,
            ];
        })->values());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'idroom' => 'required|integer',
            'iditems' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'report_type' => 'required|in:' . implode(',', self::TYPES),
            'description' => 'required|string|max:500',
        ]);

        $userId = $request->user()->idUsers;

        return DB::transaction(function () use ($validated, $userId) {
            $assignment = RoomEquipment::with(['room.building', 'item'])
                ->where('idroom', $validated['idroom'])
                ->where('iditems', $validated['iditems'])
                ->lockForUpdate()
                ->first();

            if (!$assignment) {
                return response()->json([
                    'message' => 'That equipment is not assigned to the selected room.',
                ], 422);
            }

            $alreadyReported = (int) EquipmentReport::where('status', 'Open')
                ->where('idroom', $validated['idroom'])
                ->where('iditems', $validated['iditems'])
                ->sum('quantity');

            $canReport = max((int) $assignment->quantity - $alreadyReported, 0);

            if ($validated['quantity'] > $canReport) {
                $name = $assignment->item->item_name;

                return response()->json([
                    'message' => $canReport === 0
                        ? "All {$assignment->quantity} unit(s) of {$name} in this room are already in an open report."
                        : "Only {$canReport} unit(s) of {$name} can still be reported for this room ({$alreadyReported} already in an open report).",
                ], 422);
            }

            $report = EquipmentReport::create([
                'iditems' => $validated['iditems'],
                'idroom' => $validated['idroom'],
                'building_name' => optional($assignment->room->building)->building_name,
                'room_name' => $assignment->room->room_name,
                'quantity' => $validated['quantity'],
                'report_type' => $validated['report_type'],
                'description' => trim($validated['description']),
                'status' => 'Open',
                'reported_by' => $userId,
            ]);

            $this->logActivity(
                $userId,
                "Equipment report #{$report->idreport}: '{$assignment->item->item_name}' x{$validated['quantity']} in {$assignment->room->room_name} ({$this->typeLabel($validated['report_type'])})"
            );

            return response()->json([
                'message' => 'Report submitted. The admin will review it.',
                'id' => $report->idreport,
            ], 201);
        });
    }

    // Admin: take the reported units out of the room and queue them in
    // Pending Inspection, the same as returning equipment from a room.
    public function sendToInspection(Request $request, $id)
    {
        $userId = $request->user()->idUsers;

        return DB::transaction(function () use ($id, $userId) {
            $report = EquipmentReport::with('item')->lockForUpdate()->find($id);

            if (!$report) {
                return response()->json(['message' => 'Report not found.'], 404);
            }

            if ($report->status !== 'Open') {
                return response()->json([
                    'message' => "This report is already {$report->status}.",
                ], 422);
            }

            $assignment = RoomEquipment::where('idroom', $report->idroom)
                ->where('iditems', $report->iditems)
                ->lockForUpdate()
                ->first();

            $inRoom = $assignment ? (int) $assignment->quantity : 0;

            if ($inRoom < $report->quantity) {
                return response()->json([
                    'message' => "The room now has only {$inRoom} unit(s) of {$report->item->item_name} but the report is for {$report->quantity}. Dismiss this report and ask for a new one.",
                ], 422);
            }

            $inspection = PendingInspection::create([
                'idrequestItem' => null,
                'iditems' => $report->iditems,
                'source_building' => $report->building_name,
                'source_room' => $report->room_name,
                'quantity' => $report->quantity,
                'status' => 'Pending',
                'returned_at' => now(),
            ]);

            if ($report->quantity >= $inRoom) {
                $assignment->delete();
            } else {
                $assignment->quantity -= $report->quantity;
                $assignment->save();
            }

            $report->status = 'Sent to Inspection';
            $report->reviewed_by = $userId;
            $report->reviewed_at = now();
            $report->idinspection = $inspection->idinspection;
            $report->save();

            $this->logActivity(
                $userId,
                "Equipment report #{$report->idreport}: '{$report->item->item_name}' x{$report->quantity} from {$report->room_name} sent to Pending Inspection"
            );

            return response()->json([
                'message' => 'Sent to Pending Inspection.',
            ]);
        });
    }

    public function dismiss(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'required|string|max:255',
        ]);

        $userId = $request->user()->idUsers;

        return DB::transaction(function () use ($id, $validated, $userId) {
            $report = EquipmentReport::with('item')->lockForUpdate()->find($id);

            if (!$report) {
                return response()->json(['message' => 'Report not found.'], 404);
            }

            if ($report->status !== 'Open') {
                return response()->json([
                    'message' => "This report is already {$report->status}.",
                ], 422);
            }

            $report->status = 'Dismissed';
            $report->reviewed_by = $userId;
            $report->reviewed_at = now();
            $report->review_notes = trim($validated['notes']);
            $report->save();

            $this->logActivity(
                $userId,
                "Equipment report #{$report->idreport}: '{$report->item->item_name}' dismissed - {$report->review_notes}"
            );

            return response()->json(['message' => 'Report dismissed.']);
        });
    }
}
