<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'required',
            'username' => 'required|unique:Users,username',
            'password' => 'required|min:6',
        ]);

$user = User::create([
    'full_name' => $validated['full_name'],
    'username'  => $validated['username'],
    'password'  => Hash::make($validated['password']),
    'role'      => 'dean',
]);

        return response()->json([
            'message' => 'Account created successfully.',
            'user' => $user,
        ]);
    }

    public function login(Request $request)
{
    $validated = $request->validate([
    'username' => 'required',
    'password' => 'required',
    ]);

    $user = User::where('username', $validated['username'])->first();

    if (!$user || !Hash::check($validated['password'], $user->password)) {
        return response()->json([
            'message' => 'Invalid username or password.'
        ], 401);
    }

$token = $user->createToken('cims')->plainTextToken;

return response()->json([
    'message' => 'Login successful.',
    'user' => $user,
    'token' => $token,
]);
}

public function logout(Request $request)
{
    $request->user()->currentAccessToken()->delete();

    return response()->json([
        'message' => 'Logged out successfully.'
    ]);
}

}
