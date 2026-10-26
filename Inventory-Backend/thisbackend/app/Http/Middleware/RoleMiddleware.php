<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(
        Request $request,
        Closure $next,
        ...$roles
    ): Response {
        if (!$request->user()) {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 401);
        }

        if (!in_array(strtolower((string) $request->user()->role), array_map('strtolower', $roles), true)) {
            return response()->json([
                'message' => 'Forbidden. You do not have permission to access this resource.'
            ], 403);
        }

        return $next($request);
    }
}