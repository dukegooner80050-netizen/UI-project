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
     * Reject an inactive token before auth:sanctum refreshes last_used_at.
     * AMO: 30 minutes. Every other role: 60 minutes. Requests without a bearer token pass through unchanged.
     * (The page also signs idle users out; this covers a closed tab.)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if ($plainTextToken) {
            $accessToken = PersonalAccessToken::findToken($plainTextToken);

            if ($accessToken && $accessToken->tokenable instanceof User) {
                $isAmo = strtolower((string) $accessToken->tokenable->role) === 'amo';
                $minutes = $isAmo ? 30 : 60;
                $lastActivity = $accessToken->last_used_at ?? $accessToken->created_at;

                if (!$lastActivity || $lastActivity->lt(now()->subMinutes($minutes))) {
                    $accessToken->delete();

                    return response()->json([
                        'message' => "Your session expired after {$minutes} minutes of inactivity. Please log in again.",
                        'code' => 'session_expired',
                    ], 401);
                }
            }
        }

        return $next($request);
    }
}
