<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCurrentAdminPath
{
    /**
     * Only the presently configured administration path may reach an admin API.
     *
     * The static route group remains available for route discovery compatibility,
     * while the dynamic group makes a newly saved path live without restarting PHP.
     */
    public function handle(Request $request, Closure $next)
    {
        $candidate = $request->route('adminPath') ?? $request->segment(3);
        $configured = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        if (!is_string($candidate) || !is_string($configured) || !hash_equals($configured, $candidate)) {
            abort(404);
        }

        return $next($request);
    }
}
