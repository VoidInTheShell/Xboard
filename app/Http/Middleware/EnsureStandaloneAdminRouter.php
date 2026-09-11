<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureStandaloneAdminRouter
{
    /**
     * Guard the two internal-only routes that Theme uses to discover the active
     * standalone Admin route and to render the explicit legacy fallback page.
     */
    public function handle(Request $request, Closure $next)
    {
        $tokenFile = (string) getenv('ADMIN_ROUTE_TOKEN_FILE');
        if ($tokenFile === '' || !is_readable($tokenFile)) {
            abort(503);
        }

        $expected = file_get_contents($tokenFile);
        $provided = (string) $request->header('X-Xboard-Admin-Route-Token', '');

        if ($expected === false || trim($expected) === '' || $provided === '' || !hash_equals(trim($expected), $provided)) {
            abort(403);
        }

        return $next($request);
    }
}
