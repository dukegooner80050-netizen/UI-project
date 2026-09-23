<?php

namespace App\Http\Controllers;
use App\Models\User;
use App\Traits\ActivityLogger;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    use ActivityLogger;

    public function index()
    {
        return response()->json(User::all());
    }

    public function show($id)
{
    $user = User::find($id);

    if (!$user) {
        return response()->json([
            'message' => 'User not found.'
        ], 404);
    }

    return response()->json($user);
}

public function store(Request $request)
{
    $validated = $request->validate([
        'full_name' => 'required|string|max:255',
        'username'  => 'required|string|max:255|unique:Users,username',
        'password'  => 'required|min:6',
        'role'      => 'required|in:admin,dean,cashier',
    ]);

    $user = User::create([
        'full_name' => $validated['full_name'],
        'username'  => $validated['username'],
        'password'  => Hash::make($validated['password']),
        'role'      => $validated['role'],
    ]);

    $this->logActivity(
    request()->user()->idUsers,
    "Created User '{$user->full_name}'"
);

    return response()->json([
        'message' => 'User created successfully.',
        'user' => $user,
    ], 201);
}

public function update(Request $request, $id)
{
    
    $user = User::find($id);

    if (!$user) {
        return response()->json([
            'message' => 'User not found.'
        ], 404);
    }

    $validated = $request->validate([
        'full_name' => 'required|string|max:255',
        'username'  => 'required|string|max:255|unique:Users,username,' . $id . ',idUsers',
        'role'      => 'required|in:admin,dean,cashier',
    ]);

    $user->update($validated);
    $this->logActivity(
    request()->user()->idUsers,
    "Updated User '{$user->full_name}'"
);

    return response()->json([
        'message' => 'User updated successfully.',
        'user' => $user,
    ]);
}

public function destroy($id)
{
    $user = User::find($id);

    if (!$user) {
        return response()->json([
            'message' => 'User not found.'
        ], 404);
    }
    $name = $user->full_name;
    $user->delete();

    $this->logActivity(
    request()->user()->idUsers,
    "Deleted User '{$name}'"
);

    return response()->json([
        'message' => 'User deleted successfully.'
    ]);
}
}