<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Traits\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Admin only (enforced by route middleware).
// Handles accounts that signed up on their own and are still 'pending'.
class AccountRequestController extends Controller
{
    use ActivityLogger;

    public function index()
    {
        $rows = User::where('status', 'pending')
            ->orderBy('created_at')
            ->get()
            ->map(function ($u) {
                return [
                    'idUsers' => $u->idUsers,
                    'full_name' => $u->full_name,
                    'username' => $u->username,
                    'requested_at' => $u->created_at,
                ];
            });

        return response()->json($rows);
    }

    // Let the person in, with the role the admin picks.
    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'role' => 'required|in:admin,dean,cashier',
        ]);

        return DB::transaction(function () use ($validated, $id) {

            $user = User::where('idUsers', $id)->lockForUpdate()->first();

            // Only touches accounts that are still waiting.
            if (!$user || $user->status !== 'pending') {
                return response()->json([
                    'message' => 'This account request was not found or was already handled.',
                ], 404);
            }

            $user->role = $validated['role'];
            $user->status = 'active';
            $user->save();

            $this->logActivity(
                request()->user()->idUsers,
                "Approved account request for '{$user->full_name}' as " . ucfirst($validated['role'])
            );

            return response()->json([
                'message' => 'Account approved.',
                'full_name' => $user->full_name,
                'role' => $user->role,
            ]);
        });
    }

    // Remove the request. The username becomes free again, so the person
    // can sign up again if it was a mistake.
    public function decline($id)
    {
        return DB::transaction(function () use ($id) {

            $user = User::where('idUsers', $id)->lockForUpdate()->first();

            // Never deletes an account that is already active.
            if (!$user || $user->status !== 'pending') {
                return response()->json([
                    'message' => 'This account request was not found or was already handled.',
                ], 404);
            }

            $name = $user->full_name;
            $username = $user->username;

            $user->delete();

            $this->logActivity(
                request()->user()->idUsers,
                "Declined account request for '{$name}' ({$username})"
            );

            return response()->json(['message' => 'Account request declined.']);
        });
    }
}
