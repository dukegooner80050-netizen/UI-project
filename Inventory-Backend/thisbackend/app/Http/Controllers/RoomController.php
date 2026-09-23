<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\Building;
use Illuminate\Http\Request;
use App\Traits\ActivityLogger;

class RoomController extends Controller
{
    use ActivityLogger;

    public function index(Request $request)
    {
        $query = Room::with('building');

        if ($request->has('idbuilding')) {
            $query->where('idbuilding', $request->query('idbuilding'));
        }

        return response()->json(
            $query->get()->map(function ($room) {
                return [
                    'idroom' => $room->idroom,
                    'idbuilding' => $room->idbuilding,
                    'room_name' => $room->room_name,
                    'building_name' => optional($room->building)->building_name,
                ];
            })
        );
    }

    public function show($id)
    {
        $room = Room::with('building')->find($id);

        if (!$room) {
            return response()->json([
                'message' => 'Room not found.'
            ], 404);
        }

        return response()->json([
            'idroom' => $room->idroom,
            'idbuilding' => $room->idbuilding,
            'room_name' => $room->room_name,
            'building_name' => optional($room->building)->building_name,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'idbuilding' => 'required|integer|exists:buildings,idbuilding',
            'room_name' => 'required|string|max:100',
        ]);

        $room = Room::create($validated);
        $building = Building::find($validated['idbuilding']);

        $this->logActivity(
            request()->user()->idUsers,
            "Created Room '{$room->room_name}' in '{$building->building_name}'"
        );

        return response()->json([
            'message' => 'Room created successfully.',
            'room' => $room,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $room = Room::find($id);

        if (!$room) {
            return response()->json([
                'message' => 'Room not found.'
            ], 404);
        }

        $validated = $request->validate([
            'idbuilding' => 'required|integer|exists:buildings,idbuilding',
            'room_name' => 'required|string|max:100',
        ]);

        $room->update($validated);

        $this->logActivity(
            request()->user()->idUsers,
            "Updated Room '{$room->room_name}'"
        );

        return response()->json([
            'message' => 'Room updated successfully.',
            'room' => $room,
        ]);
    }

    public function destroy($id)
    {
        $room = Room::find($id);

        if (!$room) {
            return response()->json([
                'message' => 'Room not found.'
            ], 404);
        }

        if ($room->equipment()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a room that still has equipment assigned. Remove its equipment first so stock is restored correctly.'
            ], 400);
        }

        $name = $room->room_name;

        $room->delete();

        $this->logActivity(
            request()->user()->idUsers,
            "Deleted Room '{$name}'"
        );

        return response()->json([
            'message' => 'Room deleted successfully.'
        ]);
    }
}
