<?php

namespace App\Http\Controllers;

use App\Models\UniformType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Traits\ActivityLogger;

class UniformTypeController extends Controller
{
    use ActivityLogger;
    public function index()
    {
        return response()->json(
            UniformType::with('department')->get()
        );
    }

    public function show($id)
    {
        $uniformType = UniformType::with('department')->find($id);

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
            'uniform_name' => 'required|string|max:255',
            'description'  => 'nullable|string|max:255',
            'iddept'       => 'nullable|exists:dept,iddept',
        ]);

        $deptId = $validated['iddept'] ?? null;

        if ($this->nameTaken($validated['uniform_name'], $deptId)) {
            throw ValidationException::withMessages([
                'uniform_name' => 'This uniform name is already used by '
                    . ($deptId ? 'this course.' : 'the shared (all courses) list.'),
            ]);
        }

        $validated['iddept'] = $deptId;

        $uniformType = UniformType::create($validated);
        $uniformType->load('department');
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
            'uniform_name' => 'required|string|max:255',
            'description'  => 'nullable|string|max:255',
            'iddept'       => 'nullable|exists:dept,iddept',
        ]);

        // Keep the current course when the request does not mention one, so
        // renaming a type does not silently make it shared with every course.
        $deptId = array_key_exists('iddept', $validated)
            ? $validated['iddept']
            : $uniformType->iddept;

        if ($this->nameTaken($validated['uniform_name'], $deptId, $uniformType->idUniftype)) {
            throw ValidationException::withMessages([
                'uniform_name' => 'This uniform name is already used by '
                    . ($deptId ? 'this course.' : 'the shared (all courses) list.'),
            ]);
        }

        $validated['iddept'] = $deptId;

        $uniformType->update($validated);
        $uniformType->load('department');
        $this->logActivity(
    request()->user()->idUsers,
    "Updated Uniform Type '{$uniformType->uniform_name}'"
);

        return response()->json([
            'message' => 'Uniform type updated successfully.',
            'uniformType' => $uniformType,
        ]);
    }

    /* A name only has to be unique inside its own course (or the shared list). */
    private function nameTaken(string $name, $deptId, $ignoreId = null): bool
    {
        $query = UniformType::where('uniform_name', $name);

        if ($deptId) {
            $query->where('iddept', $deptId);
        } else {
            $query->whereNull('iddept');
        }

        if ($ignoreId) {
            $query->where('idUniftype', '!=', $ignoreId);
        }

        return $query->exists();
    }

    public function destroy($id)
    {
        $uniformType = UniformType::find($id);

        if (!$uniformType) {
            return response()->json([
                'message' => 'Uniform type not found.'
            ], 404);
        }

        $name = $uniformType->uniform_name;
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