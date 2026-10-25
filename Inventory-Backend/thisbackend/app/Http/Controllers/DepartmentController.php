<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;
use App\Traits\ActivityLogger;

class DepartmentController extends Controller
{
    use ActivityLogger;
public function index()
{
    // variants_count lets the screen tell which courses are in use.
    return response()->json(
        Department::withCount('uniformVariants')->orderBy('iddept')->get()
    );
}

public function show($id)
{
    $department = Department::find($id);

    if (!$department) {
        return response()->json([
            'message' => 'Department not found.'
        ], 404);
    }

    return response()->json($department);
}

public function store(Request $request)
{
    $validated = $request->validate([
        'dept_name' => 'required|string|max:255'
    ]);
    $validated['dept_name'] = trim($validated['dept_name']);
    if (Department::whereRaw('LOWER(dept_name) = ?', [mb_strtolower($validated['dept_name'])])->exists()) {
        return response()->json([
            'message' => "A course named '{$validated['dept_name']}' already exists.",
            'errors' => ['dept_name' => ['This course already exists.']],
        ], 422);
    }

    $department = Department::create($validated);
    $this->logActivity(
    request()->user()->idUsers,
    "Created Department '{$department->dept_name}'"
);

    return response()->json([
        'message' => 'Department created successfully.',
        'department' => $department,
    ], 201);
}

public function update(Request $request, $id)
{
    $department = Department::find($id);

    if (!$department) {
        return response()->json([
            'message' => 'Department not found.'
        ], 404);
    }

    $validated = $request->validate([
        'dept_name' => 'required|string|max:255'
    ]);
    $validated['dept_name'] = trim($validated['dept_name']);
    if (Department::whereRaw('LOWER(dept_name) = ?', [mb_strtolower($validated['dept_name'])])
        ->where('iddept', '!=', $department->iddept)->exists()) {
        return response()->json([
            'message' => "A course named '{$validated['dept_name']}' already exists.",
            'errors' => ['dept_name' => ['This course already exists.']],
        ], 422);
    }

    $department->update($validated);
    $this->logActivity(
    request()->user()->idUsers,
    "Updated Department '{$department->dept_name}'"
);

    return response()->json([
        'message' => 'Department updated successfully.',
        'department' => $department,
    ]);
}

public function destroy($id)
{
    $department = Department::find($id);

    if (!$department) {
        return response()->json([
            'message' => 'Department not found.'
        ], 404);
    }

$name = $department->dept_name;

// A course that still has uniforms (or people assigned to it) can't go.
if ($department->uniformVariants()->exists()) {
    return response()->json([
        'message' => "'{$name}' still has uniform variants. Remove or move them first.",
    ], 422);
}
if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'iddept')
    && \App\Models\User::where('iddept', $department->iddept)->exists()) {
    return response()->json([
        'message' => "Some users are assigned to '{$name}'. Reassign them in Settings first.",
    ], 422);
}

$department->delete();

$this->logActivity(
    request()->user()->idUsers,
    "Deleted Department '{$name}'"
);

    return response()->json([
        'message' => 'Department deleted successfully.'
    ]);
}
}
