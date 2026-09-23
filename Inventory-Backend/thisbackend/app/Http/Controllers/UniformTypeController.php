<?php

namespace App\Http\Controllers;

use App\Models\UniformType;
use Illuminate\Http\Request;
use App\Traits\ActivityLogger;

class UniformTypeController extends Controller
{
    use ActivityLogger;
    public function index()
    {
        return response()->json(
            UniformType::all()
        );
    }

    public function show($id)
    {
        $uniformType = UniformType::find($id);

        if (!$uniformType) {
            return response()->json([
                'message' => 'Uniform type not found.'
            ], 404);
        }

        return response()->json($uniformType);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'uniform_name' => 'required|string|max:255|unique:uniformType,uniform_name',
            'description'  => 'nullable|string|max:255',
        ]);

        $uniformType = UniformType::create($validated);
        $this->logActivity(
    request()->user()->idUsers,
    "Created Uniform Type '{$uniformType->uniform_name}'"
);

        return response()->json([
            'message' => 'Uniform type created successfully.',
            'uniformType' => $uniformType,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $uniformType = UniformType::find($id);

        if (!$uniformType) {
            return response()->json([
                'message' => 'Uniform type not found.'
            ], 404);
        }

        $validated = $request->validate([
            'uniform_name' => 'required|string|max:255|unique:uniformType,uniform_name,' . $id . ',idUniftype',
            'description'  => 'nullable|string|max:255',
        ]);

        $uniformType->update($validated);
        $this->logActivity(
    request()->user()->idUsers,
    "Updated Uniform Type '{$uniformType->uniform_name}'"
);

        return response()->json([
            'message' => 'Uniform type updated successfully.',
            'uniformType' => $uniformType,
        ]);
    }

    public function destroy($id)
    {
        $uniformType = UniformType::find($id);

        if (!$uniformType) {
            return response()->json([
                'message' => 'Uniform type not found.'
            ], 404);
        }

        $uniformType->delete();
        $this->logActivity(
    request()->user()->idUsers,
    "Deleted Uniform Type '{$name}'"
);

        return response()->json([
            'message' => 'Uniform type deleted successfully.'
        ]);
    }
}