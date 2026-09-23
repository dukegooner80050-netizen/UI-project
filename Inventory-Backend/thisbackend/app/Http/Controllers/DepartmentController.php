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
    return response()->json(
        Department::all()
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
