<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\MaintenanceRecord;
use App\Models\Room;
use App\Models\RoomEquipment;
use App\Traits\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Units under maintenance: what's in repair, and how each batch ended.
// Records are created by InspectionController when units are marked
// "Need Maintenance". AMO only (route middleware).
class MaintenanceController extends Controller
{
    use ActivityLogger;

    public function index()
    {
        $open = MaintenanceRecord::where('status', 'In repair')
            ->orderBy('sent_at')
            ->get();

        $history = MaintenanceRecord::where('status', 'Closed')
            ->orderByDesc('closed_at')
            ->limit(300)
            ->get();

        // One query for which source rooms still exist.
        $rooms = Room::whereIn('idroom', $open->pluck('idroom')->filter()->unique())
            ->pluck('idroom')
            ->flip();

        return response()->json([
            'open' => $open->map(fn ($r) => $this->format($r, $rooms))->values(),
            'history' => $history->map(fn ($r) => $this->format($r, collect()))->values(),
        ]);
    }

    public function resolve(Request $request, $id)
    {
        $validated = $request->validate([
            'outcome' => 'required|in:repaired,unrepairable',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:255|required_if:outcome,unrepairable',
        ]);

        $user = $request->user();
        $userName = $user->full_name ?? $user->username;

        return DB::transaction(function () use ($id, $validated, $user, $userName) {
            $record = MaintenanceRecord::lockForUpdate()->find($id);

            if (!$record) {
                return response()->json(['message' => 'Maintenance record not found.'], 404);
            }

            if ($record->status !== 'In repair') {
                return response()->json(['message' => 'This record is already closed.'], 422);
            }

            $qty = (int) $validated['quantity'];
            $remaining = $record->remaining();

            if ($qty > $remaining) {
                return response()->json([
                    'message' => "Only {$remaining} unit(s) are still in repair in this record.",
                ], 422);
            }

            $item = $record->iditems ? Item::lockForUpdate()->find($record->iditems) : null;

            if (!$item) {
                return response()->json([
                    'message' => 'The item for this record no longer exists.',
                ], 422);
            }

            if ((int) $item->maintenance_quantity < $qty) {
                return response()->json([
                    'message' => "The item only shows {$item->maintenance_quantity} unit(s) under maintenance, so {$qty} can't be resolved.",
                ], 422);
            }

            $item->maintenance_quantity -= $qty;

            if ($validated['outcome'] === 'repaired') {
                $room = $record->idroom ? Room::find($record->idroom) : null;

                if ($room) {
                    // Back where they came from.
                    $assignment = RoomEquipment::where('idroom', $room->idroom)
                        ->where('iditems', $item->iditems)
                        ->lockForUpdate()
                        ->first();

                    if ($assignment) {
                        $assignment->quantity += $qty;
                        $assignment->save();
                    } else {
                        RoomEquipment::create([
                            'idroom' => $room->idroom,
                            'iditems' => $item->iditems,
                            'quantity' => $qty,
                        ]);
                    }

                    $destination = "back to {$room->room_name}";
                } else {
                    $item->quantity += $qty;
                    $destination = $record->idroom
                        ? 'to available stock (its room no longer exists)'
                        : 'to available stock';
                }

                $record->repaired_qty += $qty;
                $label = "Repaired x{$qty}, returned {$destination}";
            } else {
                $item->damaged_quantity += $qty;
                $record->unrepairable_qty += $qty;
                $destination = 'to the disposal list';
                $label = "Can't be repaired x{$qty}, moved {$destination}";
            }

            $item->refreshStatus();
            $item->save();

            $line = '[' . now()->format('M j') . '] ' . $label
                . (!empty($validated['notes']) ? ': ' . trim($validated['notes']) : '');
            $record->resolution_notes = trim(($record->resolution_notes ? $record->resolution_notes . "\n" : '') . $line);

            if ($record->remaining() === 0) {
                $record->status = 'Closed';
                $record->closed_at = now();
                $record->closed_by_name = $userName;
            }

            $record->save();

            $this->logActivity(
                $user->idUsers,
                "Maintenance: {$qty} '{$item->item_name}' " . ($validated['outcome'] === 'repaired'
                    ? "repaired and returned {$destination}"
                    : "marked can't be repaired, moved {$destination}")
            );

            return response()->json([
                'message' => $validated['outcome'] === 'repaired'
                    ? "{$qty} unit(s) returned {$destination}."
                    : "{$qty} unit(s) moved {$destination}.",
            ]);
        });
    }

    private function format(MaintenanceRecord $r, $existingRooms): array
    {
        $days = null;

        if ($r->status === 'In repair' && $r->sent_at) {
            $days = (int) $r->sent_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
        }

        return [
            'id' => $r->idmaintenance,
            'item_name' => $r->item_name,
            'category' => $r->category,
            'source' => $r->source,
            'origin' => $r->origin,
            'problem' => $r->problem,
            'quantity' => (int) $r->quantity,
            'remaining' => $r->remaining(),
            'repaired_qty' => (int) $r->repaired_qty,
            'unrepairable_qty' => (int) $r->unrepairable_qty,
            'goes_back_to' => $r->idroom && $existingRooms->has($r->idroom) ? $r->room_name : null,
            'room_missing' => (bool) ($r->idroom && !$existingRooms->has($r->idroom) && $r->status === 'In repair'),
            'status' => $r->status,
            'sent_at' => optional($r->sent_at)->format('Y-m-d H:i:s'),
            'sent_by' => $r->sent_by_name,
            'days_in_repair' => $days,
            'closed_at' => optional($r->closed_at)->format('Y-m-d H:i:s'),
            'closed_by' => $r->closed_by_name,
            'notes' => $r->resolution_notes,
        ];
    }
}
