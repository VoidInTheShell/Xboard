<?php

namespace App\Services\Updates;

use App\Exceptions\ApiException;
use App\Models\ServerEnrollment;
use App\Models\ServerMachine;
use App\Models\UpdateExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnrollmentService
{
    public const TTL_MINUTES = 15;

    /**
     * Enrollment credentials are sent to the panel by the node installer.
     * Public panel URLs must therefore use HTTPS; plain HTTP is only valid for
     * an explicitly local/CI endpoint.
     */
    public function validatePanelUrl(?string $panelUrl = null): string
    {
        if ($panelUrl === null) {
            $panelUrl = (string) (admin_setting('app_url') ?: config('app.url'));
        }

        $panelUrl = rtrim(trim($panelUrl), '/');
        $parts = parse_url($panelUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);

        if (
            !is_array($parts)
            || $host === ''
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || ($scheme !== 'https' && !($scheme === 'http' && $loopback))
        ) {
            throw new ApiException('机器注册要求面板使用 HTTPS；仅允许本机回环地址使用 HTTP。', 409);
        }

        return $panelUrl;
    }

    public function issue(ServerMachine $machine, ?int $createdBy = null, ?string $panelUrl = null): array
    {
        // Validate before opening the transaction or creating a one-time token.
        $this->validatePanelUrl($panelUrl);

        return DB::transaction(function () use ($machine, $createdBy): array {
            $machine = ServerMachine::query()->lockForUpdate()->findOrFail($machine->id);
            ServerEnrollment::query()
                ->where('machine_id', $machine->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $token = 'xben_' . Str::random(64);
            $enrollment = ServerEnrollment::create([
                'id' => (string) Str::uuid(),
                'machine_id' => $machine->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'created_by' => $createdBy,
            ]);

            return [
                'token' => $token,
                'expires_at' => $enrollment->expires_at->toIso8601String(),
            ];
        });
    }

    public function exchange(array $input): array
    {
        // Do this before hashing/looking up the token so an invalid panel URL
        // cannot consume a one-time enrollment credential.
        $panelUrl = $this->validatePanelUrl();
        $hash = hash('sha256', $input['enrollment_token']);

        return DB::transaction(function () use ($hash, $input, $panelUrl): array {
            $enrollment = ServerEnrollment::query()->where('token_hash', $hash)->lockForUpdate()->first();
            if (!$enrollment || !$enrollment->usable()) {
                throw new ApiException('注册失败，请生成新的安装命令。', 401);
            }

            $machine = ServerMachine::query()->lockForUpdate()->find($enrollment->machine_id);
            if (!$machine || !$machine->is_active) {
                throw new ApiException('注册失败，请生成新的安装命令。', 401);
            }

            $scope = 'machine:' . $machine->id;
            $executor = UpdateExecutor::query()->where('scope', $scope)->lockForUpdate()->first();
            $secret = 'xbu_' . Str::random(64);
            $attributes = [
                'name' => $machine->name,
                'kind' => 'node',
                'machine_id' => $machine->id,
                'enabled' => true,
                'secret_hash' => hash('sha256', $secret),
                'protocol' => 2,
                'state_schema' => 1,
                'installation_method' => $input['installation_method'],
                'updater_version' => $input['updater_version'],
            ];
            if ($executor) {
                $executor->update($attributes);
            } else {
                $executor = UpdateExecutor::create($attributes + [
                    'id' => (string) Str::uuid(),
                    'scope' => $scope,
                    'blocked' => false,
                ]);
            }

            // Mark used in the same transaction as credential rotation. A retry
            // cannot receive a second machine/executor credential pair.
            $enrollment->update(['used_at' => now()]);

            return [
                'machine_id' => (int) $machine->id,
                'panel_url' => $panelUrl,
                'machine_token' => $machine->token,
                'executor_id' => $executor->id,
                'executor_secret' => $secret,
                'node_version' => $input['node_version'],
                'updater_version' => $input['updater_version'],
                'installation_method' => $input['installation_method'],
            ];
        });
    }
}
