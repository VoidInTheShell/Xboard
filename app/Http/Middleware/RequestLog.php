<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;

class RequestLog
{
    private const SENSITIVE_KEYS = ['password', 'token', 'secret', 'key', 'api_key'];

    // Native config objects contain protocol-dependent credentials at arbitrary
    // depths. Keep operation metadata, but never copy these payloads to audit logs.
    private const PRIVATE_CONFIG_KEYS = ['xray_config', 'client_settings', 'cert_config', 'protocol_settings', 'credential', 'service_credential', 'config_patch', 'config_override', 'config'];

    public static function redactRequestData(array $data): array
    {
        foreach ($data as $key => $value) {
            $name = strtolower((string) $key);
            if (in_array($name, self::PRIVATE_CONFIG_KEYS, true)
                || preg_match('/password|token|secret|decryption|encryption|private.?key|cert_content|key_content|dns_env|auth_data|uuid/i', $name)
                || in_array($name, self::SENSITIVE_KEYS, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redactRequestData($value);
            }
        }
        return $data;
    }

    public function handle($request, Closure $next)
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $response = $next($request);

        try {
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                return $response;
            }

            $action = $this->resolveAction($request->path());
            $data = self::redactRequestData($request->all());

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'request_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    private function resolveAction(string $path): string
    {
        // api/v2/{secure_path}/user/update → user.update
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path);
        // gift-card/create-template → gift_card.create_template
        $path = str_replace('-', '_', $path);
        // user/update → user.update, server/manage/sort → server_manage.sort
        $segments = explode('/', $path);
        $method = array_pop($segments);
        $resource = implode('_', $segments);

        return $resource . '.' . $method;
    }
}

