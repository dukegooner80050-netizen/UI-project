<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// While an account still has a temporary password (set by the AMO), the only
// thing it can do is change it. Everything else is refused here, so this
// is enforced by the server and not just by the page.
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && !$request->is('api/change-password')) {
            return response()->json([
                'message' => 'You must change your temporary password before continuing.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
