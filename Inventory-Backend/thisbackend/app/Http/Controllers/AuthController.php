<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'required',
            'username' => 'required|unique:users,username',
            'password' => 'required|min:6',
        ]);

$user = new User([
    'full_name' => $validated['full_name'],
    'username'  => $validated['username'],
    'password'  => Hash::make($validated['password']),
    'role'      => 'dean',
]);

        // Self sign-ups cannot sign in until an admin approves them.
        // (status is not mass assignable, so it is set explicitly.)
        $user->status = 'pending';
        $user->save();

        return response()->json([
            'message' => 'Your account has been created and is waiting for administrator approval.',
            'status'  => 'pending',
        ], 201);
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

// Checked after the password on purpose, so only someone who knows
// the password can learn that the account is still waiting.
if (($user->status ?? 'active') === 'pending') {
    return response()->json([
        'message' => 'Your account is waiting for administrator approval. Please try again once an admin has approved it.',
        'code' => 'account_pending',
    ], 403);
}

if (($user->status ?? 'active') !== 'active') {
    return response()->json([
        'message' => 'Your account is not active. Please contact the administrator.',
    ], 403);
}

// Only Admin accounts share the two-session limit. Other roles are unaffected.
// Admin tokens expire after 30 minutes without activity; stale tokens are
// removed before checking the limit so abandoned sessions release their slots.
$token = DB::transaction(function () use ($user) {
    $idleCutoff = now()->subMinutes(30);

    if (strtolower((string) $user->role) === 'admin') {
        // Lock the same admin row for every admin login attempt. This serializes
        // concurrent logins so two simultaneous requests cannot both take the last slot.
        $adminIds = User::whereRaw('LOWER(role) = ?', ['admin'])
            ->orderBy('idUsers')
            ->lockForUpdate()
            ->pluck('idUsers');

        $adminTokens = PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $adminIds);

        // A token with no recorded use is measured from creation time.
        $adminTokens->where(function ($query) use ($idleCutoff) {
            $query->where('last_used_at', '>=', $idleCutoff)
                ->orWhere(function ($query) use ($idleCutoff) {
                    $query->whereNull('last_used_at')
                        ->where('created_at', '>=', $idleCutoff);
                });
        });
        $activeAdminTokens = $adminTokens->count();

        // Delete stale Admin tokens to release slots and prevent old credentials
        // from continuing to work after the inactivity timeout.
        PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $adminIds)
            ->where(function ($query) use ($idleCutoff) {
                $query->where('last_used_at', '<', $idleCutoff)
                    ->orWhere(function ($query) use ($idleCutoff) {
                        $query->whereNull('last_used_at')
                            ->where('created_at', '<', $idleCutoff);
                    });
            })->delete();

        if ($activeAdminTokens >= 2) {
            return null;
        }
    }

    return $user->createToken(
        strtolower((string) $user->role) === 'admin' ? 'cims-admin-session' : 'cims'
    )->plainTextToken;
});

if ($token === null) {
    return response()->json([
        'message' => 'The maximum of 2 simultaneous Admin sessions has been reached. Please try again after an Admin logs out or their session expires after 30 minutes of inactivity.',
        'code' => 'admin_session_limit',
    ], 429);
}

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
