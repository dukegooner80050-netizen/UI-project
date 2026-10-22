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
        'username'  => 'required|string|max:255|unique:users,username',
        'password'  => 'required|min:6',
        'role'      => 'required|in:amo,department_head,admin,instructor,cashier',
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
        'username'  => 'required|string|max:255|unique:users,username,' . $id . ',idUsers',
        'role'      => 'required|in:amo,department_head,admin,instructor,cashier',
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

// AMO only (route middleware). Sets a temporary password, shown once to
// the AMO to pass on. The user is signed out everywhere and must choose a
// new password at their next login.
public function resetPassword($id)
{
    $user = User::find($id);

    if (!$user) {
        return response()->json(['message' => 'User not found.'], 404);
    }

    if ((int) $user->idUsers === (int) request()->user()->idUsers) {
        return response()->json(['message' => 'Use Change Password in Settings for your own account.'], 422);
    }

    if (($user->status ?? 'active') === 'pending') {
        return response()->json(['message' => 'This account has not been approved yet.'], 422);
    }

    // No look-alike characters (0/O, 1/l/I) so it is easy to read out.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $temp = '';
    for ($i = 0; $i < 10; $i++) {
        $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $user->password = $temp; // hashed by the model cast
    $user->must_change_password = true;
    $user->save();
    $user->tokens()->delete();

    $this->logActivity(
        request()->user()->idUsers,
        "Reset password for '{$user->full_name}'"
    );

    return response()->json([
        'message' => 'Password reset.',
        'temporary_password' => $temp,
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