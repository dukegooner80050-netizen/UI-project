<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\Item;
use App\Models\RoomEquipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Traits\ActivityLogger;

class RoomEquipmentController extends Controller
{
    use ActivityLogger;

    public function index($roomId)
    {
        $room = Room::find($roomId);

        if (!$room) {
            return response()->json([
                'message' => 'Room not found.'
            ], 404);
        }

        $equipment = RoomEquipment::with('item')
            ->where('idroom', $roomId)
            ->get()
            ->map(function ($e) {
                return [
                    'idroomequipment' => $e->idroomequipment,
                    'idroom' => $e->idroom,
                    'iditems' => $e->iditems,
                    'item_name' => optional($e->item)->item_name,
                    'quantity' => $e->quantity,
                ];
            });

        return response()->json($equipment);
    }

    public function store(Request $request, $roomId)
    {
        $room = Room::find($roomId);

        if (!$room) {
            return response()->json([
                'message' => 'Room not found.'
            ], 404);
        }

        $validated = $request->validate([
            'iditems' => 'required|integer|exists:items,iditems',
            'quantity' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($validated, $roomId) {

            $item = Item::find($validated['iditems']);

            if ($item->category !== 'School Equipment') {
                return response()->json([
                    'message' => 'Only School Equipment items can be assigned to a room.'
                ], 400);
            }

            if ($item->quantity < $validated['quantity']) {
                return response()->json([
                    'message' => "Insufficient available stock for '{$item->item_name}'. Only {$item->quantity} available."
                ], 400);
            }

            // If this item is already assigned to this room, add to the
            // existing assignment instead of creating a duplicate row.
            $existing = RoomEquipment::where('idroom', $roomId)
                ->where('iditems', $validated['iditems'])
                ->first();

            if ($existing) {
                $existing->quantity += $validated['quantity'];
                $existing->save();
                $roomEquipment = $existing;
            } else {
                $roomEquipment = RoomEquipment::create([
                    'idroom' => $roomId,
                    'iditems' => $validated['iditems'],
                    'quantity' => $validated['quantity'],
                ]);
            }

            $item->quantity -= $validated['quantity'];
            $this->updateItemStatus($item);
            $item->save();

            $room = Room::find($roomId);
            $this->logActivity(
                request()->user()->idUsers,
                "Assigned {$validated['quantity']} '{$item->item_name}' to Room '{$room->room_name}'"
            );

            return response()->json([
                'message' => 'Equipment assigned to room successfully.',
                'roomEquipment' => $roomEquipment,
            ], 201);
        });
    }

    public function update(Request $request, $roomId, $id)
    {
        $roomEquipment = RoomEquipment::where('idroom', $roomId)->find($id);

        if (!$roomEquipment) {
            return response()->json([
                'message' => 'Room equipment assignment not found.'
            ], 404);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($validated, $roomEquipment, $roomId) {

            $item = Item::find($roomEquipment->iditems);
            $newQty = $validated['quantity'];
            $oldQty = $roomEquipment->quantity;
            $delta = $newQty - $oldQty;

            if ($delta > 0 && $item->quantity < $delta) {
                return response()->json([
                    'message' => "Insufficient available stock for '{$item->item_name}'. Only {$item->quantity} more available."
                ], 400);
            }

            $item->quantity -= $delta;
            $this->updateItemStatus($item);
            $item->save();

            $roomEquipment->quantity = $newQty;
            $roomEquipment->save();

            $room = Room::find($roomId);
            $this->logActivity(
                request()->user()->idUsers,
                "Updated '{$item->item_name}' in Room '{$room->room_name}' to {$newQty}"
            );

            return response()->json([
                'message' => 'Room equipment updated successfully.',
                'roomEquipment' => $roomEquipment,
            ]);
        });
    }

    public function destroy($roomId, $id)
    {
        $roomEquipment = RoomEquipment::where('idroom', $roomId)->find($id);

        if (!$roomEquipment) {
            return response()->json([
                'message' => 'Room equipment assignment not found.'
            ], 404);
        }

        return DB::transaction(function () use ($roomEquipment, $roomId) {

            $item = Item::find($roomEquipment->iditems);
            $qty = $roomEquipment->quantity;
            $itemName = optional($item)->item_name ?? 'Unknown Item';

            if ($item) {
                $item->quantity += $qty;
                $this->updateItemStatus($item);
                $item->save();
            }

            $room = Room::find($roomId);
            $roomEquipment->delete();

            $this->logActivity(
                request()->user()->idUsers,
                "Removed '{$itemName}' ({$qty}) from Room '{$room->room_name}'"
            );

            return response()->json([
                'message' => 'Equipment removed from room and stock restored.'
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
