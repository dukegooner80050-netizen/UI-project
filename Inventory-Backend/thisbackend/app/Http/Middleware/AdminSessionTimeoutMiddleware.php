<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AdminSessionTimeoutMiddleware
{
    /**
     * Reject an inactive Admin token before auth:sanctum refreshes last_used_at.
     * Non-admin tokens and requests without bearer tokens pass through unchanged.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if ($plainTextToken) {
            $accessToken = PersonalAccessToken::findToken($plainTextToken);

            if ($accessToken && $accessToken->tokenable instanceof User
                && strtolower((string) $accessToken->tokenable->role) === 'admin') {
                $lastActivity = $accessToken->last_used_at ?? $accessToken->created_at;

                if (!$lastActivity || $lastActivity->lt(now()->subMinutes(30))) {
                    $accessToken->delete();

                    return response()->json([
                        'message' => 'Your Admin session expired after 30 minutes of inactivity. Please log in again.',
                        'code' => 'admin_session_expired',
                    ], 401);
                }
            }
        }

        return $next($request);
    }
}
