<?php

namespace App\Http\Controllers;

use App\Models\Building;
use Illuminate\Http\Request;
use App\Traits\ActivityLogger;

class BuildingController extends Controller
{
    use ActivityLogger;

    public function index()
    {
        return response()->json(
            Building::all()
        );
    }

    public function show($id)
    {
        $building = Building::find($id);

        if (!$building) {
            return response()->json([
                'message' => 'Building not found.'
            ], 404);
        }

        return response()->json($building);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'building_name' => 'required|string|max:100|unique:buildings,building_name'
        ]);

        $building = Building::create($validated);
        $this->logActivity(
            request()->user()->idUsers,
            "Created Building '{$building->building_name}'"
        );

        return response()->json([
            'message' => 'Building created successfully.',
            'building' => $building,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $building = Building::find($id);

        if (!$building) {
            return response()->json([
                'message' => 'Building not found.'
            ], 404);
        }

        $validated = $request->validate([
            'building_name' => 'required|string|max:100|unique:buildings,building_name,' . $id . ',idbuilding'
        ]);

        $building->update($validated);
        $this->logActivity(
            request()->user()->idUsers,
            "Updated Building '{$building->building_name}'"
        );

        return response()->json([
            'message' => 'Building updated successfully.',
            'building' => $building,
        ]);
    }

    public function destroy($id)
    {
        $building = Building::find($id);

        if (!$building) {
            return response()->json([
                'message' => 'Building not found.'
            ], 404);
        }

        if ($building->rooms()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a building that still has rooms. Delete its rooms first.'
            ], 400);
        }

        $name = $building->building_name;

        $building->delete();

        $this->logActivity(
            request()->user()->idUsers,
            "Deleted Building '{$name}'"
        );

        return response()->json([
            'message' => 'Building deleted successfully.'
        ]);
    }
}
