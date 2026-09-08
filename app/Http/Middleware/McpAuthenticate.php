<?php

namespace App\Http\Middleware;

use App\Services\McpKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class McpAuthenticate
{
    public function __construct(private readonly McpKeyService $keys)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->originAllowed($request)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32021, 'message' => 'Origin is not allowed.'],
            ], 403);
        }

        if (!$this->keys->enabled()) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32003, 'message' => 'Xboard MCP service is disabled.'],
            ], 503);
        }

        $secret = $request->bearerToken();
        $key = is_string($secret) ? $this->keys->authenticate($secret, $request) : null;
        if (!$key) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32001, 'message' => 'Invalid, expired, or revoked MCP key.'],
            ], 401, ['WWW-Authenticate' => 'Bearer realm="Xboard MCP"']);
        }

        $request->attributes->set('mcp_key', $key);
        $request->attributes->set('mcp_admin', $key->owner);
        $request->setUserResolver(fn() => $key->owner);

        return $next($request);
    }

    private function originAllowed(Request $request): bool
    {
        $origin = $request->header('Origin');
        if (!is_string($origin) || trim($origin) === '') {
            return true;
        }

        $candidate = $this->normalizeOrigin($origin);
        if ($candidate === null) {
            return false;
        }

        $allowed = array_filter([
            $this->normalizeOrigin($request->getSchemeAndHttpHost()),
            $this->normalizeOrigin((string) config('app.url')),
        ]);

        return in_array($candidate, $allowed, true);
    }

    private function normalizeOrigin(string $value): ?string
    {
        $parts = parse_url(trim($value));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return "{$scheme}://{$host}{$port}";
    }
}
