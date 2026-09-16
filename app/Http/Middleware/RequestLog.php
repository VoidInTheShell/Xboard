<?php

namespace App\Http\Middleware;

use App\Exceptions\ChangeVersionConflictException;
use App\Services\AdminOperationCatalog;
use App\Services\ChangeEventService;
use Closure;
use Illuminate\Support\Facades\DB;

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
        if ($request->method() !== 'POST' && !\App\Services\Logs\LogSettings::get()['auditReads']) return $next($request);

        $operation = app(AdminOperationCatalog::class)->describeRequest($request);
        $isMutation = $operation !== null && !$operation['read_only'];
        $initialTransactionLevel = DB::transactionLevel();

        if ($isMutation) {
            DB::beginTransaction();
        }

        try {
            if ($isMutation) {
                app(ChangeEventService::class)->assertExpectedVersion($request);
            }

            $response = $next($request);
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                $this->finishTransaction($isMutation, $initialTransactionLevel, false);
                return $response;
            }

            $successful = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
            if (!$successful) {
                $this->finishTransaction($isMutation, $initialTransactionLevel, false);
                \App\Services\Logs\AuditWriter::attempt($request,$operation['id'] ?? $this->resolveAction($request->path()),$response->getStatusCode());
                return $response;
            }

            $changeEvents = app(ChangeEventService::class);
            /* AuditWriter applies collection policy and bounded redaction. */
            \App\Services\Logs\AuditWriter::write($request,$operation['id'] ?? $this->resolveAction($request->path()),$response->getStatusCode());

            if ($isMutation) {
                $changeEvents->commitMutation($request, $operation);
            }

            $this->finishTransaction($isMutation, $initialTransactionLevel, true);
            return $response;
        } catch (ChangeVersionConflictException $e) {
            $this->finishTransaction($isMutation, $initialTransactionLevel, false);
            \App\Services\Logs\AuditWriter::attempt($request,$operation['id'] ?? $this->resolveAction($request->path()),409);
            return response()->json([
                'status' => 'fail',
                'message' => $e->getMessage(),
                'data' => [
                    'expected_version' => $e->expectedVersion,
                    'current_version' => $e->currentVersion,
                ],
            ], 409);
        } catch (\Throwable $e) {
            $this->finishTransaction($isMutation, $initialTransactionLevel, false);
            $status=$e instanceof \Illuminate\Validation\ValidationException ? 422 : ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode() : 500);
            \App\Services\Logs\AuditWriter::attempt($request,$operation['id'] ?? $this->resolveAction($request->path()),$status);
            if (!isset($response)) {
                throw $e;
            }

            \Log::warning('Audit log write failed: ' . $e->getMessage());
            if ($isMutation) {
                return response()->json([
                    'status' => 'fail',
                    'message' => '管理操作未提交：无法写入同步版本。',
                ], 500);
            }

            return $response;
        }
    }

    private function finishTransaction(bool $started, int $initialLevel, bool $commit): void
    {
        if (!$started || DB::transactionLevel() <= $initialLevel) {
            return;
        }

        if ($commit) {
            DB::commit();
        } else {
            DB::rollBack();
        }
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

