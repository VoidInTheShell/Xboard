<?php

namespace App\Services\Certificates;

use App\Exceptions\ApiException;
use App\Models\Server;
use App\Models\ServerCertificate;
use App\Models\ServerCertificateBinding;
use App\Models\ServerMachine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CertificateService
{
    public const RENEWABLE_SOURCES = [
        ServerCertificate::SOURCE_ACME_HTTP,
        ServerCertificate::SOURCE_ACME_DNS,
        ServerCertificate::SOURCE_SELF_SIGNED,
    ];

    public function listForMachine(int $machineId): Collection
    {
        return ServerCertificate::query()
            ->where('machine_id', $machineId)
            ->with(['bindings.server.machine'])
            ->orderBy('name')
            ->get();
    }

    public function findForMachine(string $id, int $machineId): ServerCertificate
    {
        $certificate = ServerCertificate::query()
            ->whereKey($id)
            ->where('machine_id', $machineId)
            ->with(['bindings.server.machine'])
            ->first();
        if (!$certificate) {
            throw new ApiException('证书资源不存在或不属于当前服务器。', 404);
        }
        return $certificate;
    }

    public function validateDraft(array $input, ?ServerCertificate $existing = null): array
    {
        return $this->normalize($input, $existing, false);
    }

    public function save(array $input): ServerCertificate
    {
        $machineId = $this->machineId($input);
        $certificateId = !empty($input['id']) ? (string) $input['id'] : null;

        return DB::transaction(function () use ($machineId, $certificateId, $input): ServerCertificate {
            // The revision and the material/status fields must be calculated
            // from the same row lock that is eventually written.  Computing
            // them before the transaction lets two concurrent saves both
            // reuse the same revision and lets an older status win last.
            $existing = $certificateId
                ? ServerCertificate::query()
                    ->whereKey($certificateId)
                    ->where('machine_id', $machineId)
                    ->lockForUpdate()
                    ->first()
                : null;
            if ($certificateId && !$existing) {
                throw new ApiException('证书资源不存在或不属于当前服务器。', 404);
            }
            $normalized = $this->normalize($input, $existing, true);
            if ($existing) {
                $certificate = $existing;
            } else {
                $certificate = new ServerCertificate();
                $certificate->id = (string) Str::uuid();
                $certificate->machine_id = $machineId;
            }

            $certificate->fill(Arr::only($normalized, [
                'name', 'source_type', 'domains', 'auto_renew', 'email',
                'dns_provider', 'certificate_path', 'private_key_path',
                'status', 'not_before_at', 'expires_at', 'fingerprint',
                'last_renewed_at', 'next_renewal_at', 'last_error', 'revision',
            ]));

            foreach (['dns_credentials', 'certificate_content', 'private_key_content'] as $secret) {
                if (array_key_exists($secret, $normalized)) {
                    $certificate->{$secret} = $normalized[$secret];
                }
            }

            $certificate->save();
            return $certificate->fresh(['bindings.server.machine']);
        });
    }

    public function renew(string $id, int $machineId): ServerCertificate
    {
        return DB::transaction(function () use ($id, $machineId): ServerCertificate {
            $certificate = ServerCertificate::query()
                ->whereKey($id)
                ->where('machine_id', $machineId)
                ->lockForUpdate()
                ->first();
            if (!$certificate) {
                throw new ApiException('证书资源不存在或不属于当前服务器。', 404);
            }
            if (!in_array($certificate->source_type, self::RENEWABLE_SOURCES, true)) {
                throw new ApiException('路径或 PEM 内容证书不能由面板直接续签，请更新证书资源后再应用。', 422);
            }

            $certificate->forceFill([
                'status' => 'issuing',
                'last_error' => null,
                'next_renewal_at' => null,
                'revision' => (int) $certificate->revision + 1,
            ])->save();

            return $certificate->fresh(['bindings.server.machine']);
        });
    }

    public function drop(string $id, int $machineId): void
    {
        $certificate = $this->findForMachine($id, $machineId);
        if ($certificate->bindings()->exists()) {
            throw new ApiException('证书仍被节点或发布端点引用，请先解除所有引用。', 409);
        }
        $certificate->delete();
    }

    /**
     * Convert the new node selection into the legacy Node cert_config shape.
     * The Node agent still consumes this shape during the compatibility window;
     * the resource ID and binding remain the panel-side source of ownership.
     */
    public function prepareNodeParams(array $params, ?Server $server = null): array
    {
        $hasSelection = array_key_exists('certificate_ref_mode', $params)
            || array_key_exists('certificate_id', $params)
            || array_key_exists('certificate_path', $params)
            || array_key_exists('private_key_path', $params);
        if (!$hasSelection) return $params;

        $mode = $params['certificate_ref_mode'] ?? null;
        $certificateId = $params['certificate_id'] ?? null;

        // An old node may only have cert_config.  A frontend save that has not
        // yet loaded a migrated resource must not erase that legacy material.
        if ($mode === null && $certificateId === null
            && $server?->cert_config && !array_key_exists('cert_config', $params)) {
            return $params;
        }

        if ($mode === null || ($mode !== 'path' && $certificateId === null)) {
            $params['certificate_id'] = null;
            $params['certificate_ref_mode'] = null;
            $params['cert_config'] = null;
            return $params;
        }

        $machineId = (int) ($params['machine_id'] ?? $server?->machine_id ?? 0);
        if ($machineId < 1) {
            throw new ApiException('节点绑定证书前必须先关联服务器。', 422);
        }

        if ($mode === 'path') {
            $certificate = $this->ensurePathResource(
                $machineId,
                (string) ($params['certificate_path'] ?? ''),
                (string) ($params['private_key_path'] ?? ''),
                (string) ($params['name'] ?? '路径证书'),
                (string) ($params['host'] ?? ''),
            );
        } elseif ($mode === 'server_certificate') {
            $certificate = $this->findForMachine((string) $certificateId, $machineId);
        } else {
            throw new ApiException('不支持的证书引用模式。', 422);
        }

        $params['certificate_id'] = $certificate->id;
        $params['certificate_ref_mode'] = $mode;
        // Never write decrypted DNS credentials or PEM private keys to the
        // legacy v2_server row.  The machine-authenticated projection is
        // generated from the encrypted resource only when building its node
        // configuration response.
        $params['cert_config'] = $this->toLegacyConfig($certificate, false);
        unset($params['certificate_path'], $params['private_key_path']);
        return $params;
    }

    public function syncBinding(Server $server): void
    {
        if (!$server->certificate_id || !$server->machine_id) {
            ServerCertificateBinding::query()->where('server_id', $server->id)->delete();
            return;
        }

        $certificate = ServerCertificate::query()
            ->whereKey($server->certificate_id)
            ->where('machine_id', $server->machine_id)
            ->first();
        if (!$certificate) {
            throw new ApiException('节点绑定的证书资源不存在或服务器归属不一致。', 422);
        }

        DB::transaction(function () use ($server, $certificate): void {
            ServerCertificateBinding::query()->where('server_id', $server->id)->delete();
            ServerCertificateBinding::create([
                'certificate_id' => $certificate->id,
                'server_id' => $server->id,
                'target_type' => 'managed_inbound',
                'target_id' => (string) $server->id,
                'target_name' => $server->name,
                'instance_name' => $server->machine?->name,
                'protocol' => $server->type,
                'usage' => 'server',
            ]);
        });
    }

    public function toLegacyConfig(ServerCertificate $certificate, bool $includeSecrets = true): array
    {
        $domains = array_values($certificate->domains ?? []);
        $domain = $domains[0] ?? null;
        $base = [
            'domains' => $domains,
            'auto_renew' => (bool) $certificate->auto_renew,
            'revision' => (int) $certificate->revision,
        ];
        $config = match ($certificate->source_type) {
            ServerCertificate::SOURCE_ACME_HTTP => [
                'cert_mode' => 'http',
                'auto_tls' => true,
                'domain' => $domain,
                'email' => $certificate->email,
            ],
            ServerCertificate::SOURCE_ACME_DNS => [
                'cert_mode' => 'dns',
                'auto_tls' => true,
                'domain' => $domain,
                'email' => $certificate->email,
                'dns_provider' => $certificate->dns_provider,
                'dns_env' => $this->dnsEnvironment($certificate->dns_credentials),
            ],
            ServerCertificate::SOURCE_PATH => [
                'cert_mode' => 'file',
                'domain' => $domain,
                'cert_file' => $certificate->certificate_path,
                'key_file' => $certificate->private_key_path,
            ],
            ServerCertificate::SOURCE_CONTENT => [
                'cert_mode' => 'content',
                'domain' => $domain,
                'cert_content' => $certificate->certificate_content,
                'key_content' => $certificate->private_key_content,
            ],
            ServerCertificate::SOURCE_SELF_SIGNED => [
                'cert_mode' => 'self',
                'domain' => $domain,
            ],
            default => ['cert_mode' => 'none'],
        };
        $config = array_merge($base, $config);
        if (!$includeSecrets) {
            foreach (['dns_env', 'cert_content', 'key_content'] as $secret) {
                unset($config[$secret]);
            }
        }
        return $config;
    }

    public function toControlPlaneArray(ServerCertificate $certificate): array
    {
        $certificate->loadMissing(['bindings.server.machine']);
        $data = [
            'id' => (string) $certificate->id,
            'machine_id' => (int) $certificate->machine_id,
            'name' => $certificate->name,
            'source_type' => $certificate->source_type,
            'domains' => array_values($certificate->domains ?? []),
            'auto_renew' => (bool) $certificate->auto_renew,
            'revision' => (int) $certificate->revision,
            'email' => $certificate->email,
            'dns_provider' => $certificate->dns_provider,
            'dns_configured' => is_array($certificate->dns_credentials)
                ? $certificate->dns_credentials !== []
                : trim((string) $certificate->dns_credentials) !== '',
            'certificate_path' => $certificate->source_type === ServerCertificate::SOURCE_PATH
                ? $certificate->certificate_path : null,
            'private_key_path' => $certificate->source_type === ServerCertificate::SOURCE_PATH
                ? $certificate->private_key_path : null,
            'status' => $this->effectiveStatus($certificate),
            'not_before_at' => $certificate->not_before_at?->toIso8601String(),
            'expires_at' => $certificate->expires_at?->toIso8601String(),
            'fingerprint' => $certificate->fingerprint,
            'last_renewed_at' => $certificate->last_renewed_at?->toIso8601String(),
            'next_renewal_at' => $certificate->next_renewal_at?->toIso8601String(),
            'last_error' => $certificate->last_error,
            'references' => $certificate->bindings->map(fn (ServerCertificateBinding $binding) => [
                'target_type' => $binding->target_type,
                'target_id' => (string) $binding->target_id,
                'target_name' => $binding->target_name ?: ($binding->server?->name ?? '未知节点'),
                'instance_name' => $binding->instance_name ?: ($binding->server?->machine?->name ?? '未知服务器'),
                'protocol' => $binding->protocol ?: ($binding->server?->type ?? 'unknown'),
                'usage' => $binding->usage,
            ])->values()->all(),
            'created_at' => $certificate->created_at?->toIso8601String(),
            'updated_at' => $certificate->updated_at?->toIso8601String(),
        ];
        return $data;
    }

    /**
     * Return the non-secret compatibility view used by Admin responses.  A
     * machine-authenticated node projection may request the full legacy shape,
     * but an Admin/MCP response must never decrypt DNS credentials or PEM keys.
     */
    public function toAdminLegacyConfig(ServerCertificate $certificate): array
    {
        return $this->toLegacyConfig($certificate, false);
    }

    public function toMachineProjection(ServerCertificate $certificate): array
    {
        return [
            'id' => (string) $certificate->id,
            'revision' => (int) $certificate->revision,
            'cert_config' => $this->toLegacyConfig($certificate, true),
        ];
    }

    public function redactLegacyConfig(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach (['dns_env', 'cert_content', 'key_content', 'private_key', 'private_key_content'] as $key) {
            if (array_key_exists($key, $value)) {
                $value[$key] = '[configured]';
            }
        }
        return $value;
    }

    public function effectiveForNode(Server $node): ?ServerCertificate
    {
        if (!$node->certificate_id || !$node->machine_id) return null;
        return ServerCertificate::query()
            ->whereKey($node->certificate_id)
            ->where('machine_id', $node->machine_id)
            ->first();
    }

    private function normalize(array $input, ?ServerCertificate $existing, bool $forSave): array
    {
        $source = trim((string) ($input['source_type'] ?? $existing?->source_type ?? ''));
        if (!in_array($source, ServerCertificate::SOURCES, true)) {
            throw new ApiException('证书来源类型不受支持。', 422);
        }

        $domains = $this->domains($input['domains'] ?? $existing?->domains ?? []);
        $name = trim((string) ($input['name'] ?? $existing?->name ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new ApiException('证书名称不能为空且不能超过 255 个字符。', 422);
        }

        $normalized = [
            'name' => $name,
            'source_type' => $source,
            'domains' => $domains,
            'auto_renew' => (bool) ($input['auto_renew'] ?? $existing?->auto_renew ?? true),
            'revision' => $existing ? (int) $existing->revision : 1,
            'email' => $this->nullableString($input['email'] ?? $existing?->email),
            'dns_provider' => $this->nullableString($input['dns_provider'] ?? $existing?->dns_provider),
            'certificate_path' => $this->nullableString($input['certificate_path'] ?? $existing?->certificate_path),
            'private_key_path' => $this->nullableString($input['private_key_path'] ?? $existing?->private_key_path),
            'status' => $existing?->status ?? 'pending',
            'not_before_at' => $existing?->not_before_at,
            'expires_at' => $existing?->expires_at,
            'fingerprint' => $existing?->fingerprint,
            'last_renewed_at' => $existing?->last_renewed_at,
            'next_renewal_at' => $existing?->next_renewal_at,
            'last_error' => null,
        ];

        foreach (['dns_credentials', 'certificate_content', 'private_key_content'] as $secret) {
            if (array_key_exists($secret, $input)
                && ((!is_array($input[$secret]) && trim((string) $input[$secret]) !== '')
                    || (is_array($input[$secret]) && $input[$secret] !== []))) {
                $normalized[$secret] = is_array($input[$secret])
                    ? $input[$secret]
                    : (string) $input[$secret];
            } elseif ($existing) {
                $normalized[$secret] = $existing->{$secret};
            }
        }

        // Validate after merging replacement/legacy secrets.  Previously the
        // content branch inspected the pre-secret normalized array and
        // rejected every otherwise valid PEM pair.
        $this->validateSourceRequirements($source, $normalized, $input, $existing);

        $materialChanged = $existing && (
            $existing->source_type !== $source
            || array_values($existing->domains ?? []) !== $domains
            || $this->secretInputChanged($input, $existing, 'dns_credentials')
            || $this->secretInputChanged($input, $existing, 'certificate_content')
            || $this->secretInputChanged($input, $existing, 'private_key_content')
            || $existing->certificate_path !== $normalized['certificate_path']
            || $existing->private_key_path !== $normalized['private_key_path']
        );
        $desiredChanged = !$existing || $materialChanged
            || (bool) $existing->auto_renew !== (bool) $normalized['auto_renew']
            || $existing->email !== $normalized['email']
            || $existing->dns_provider !== $normalized['dns_provider'];
        if ($desiredChanged) {
            $normalized['revision'] = $existing ? (int) $existing->revision + 1 : 1;
        }
        if ($materialChanged) {
            $normalized['status'] = 'pending';
            $normalized['not_before_at'] = null;
            $normalized['expires_at'] = null;
            $normalized['fingerprint'] = null;
            $normalized['last_renewed_at'] = null;
            $normalized['next_renewal_at'] = null;
        }

        if ($source === ServerCertificate::SOURCE_CONTENT) {
            $metadata = $this->pemMetadata(
                (string) ($normalized['certificate_content'] ?? ''),
                (string) ($normalized['private_key_content'] ?? ''),
                $domains,
            );
            $normalized = array_merge($normalized, $metadata, ['status' => 'valid']);
        } elseif ($source === ServerCertificate::SOURCE_PATH) {
            // A path belongs to the machine, not to the panel host.  The
            // machine must prove readability and a valid pair in its status
            // report before this resource can become valid.
            $normalized['status'] = 'pending';
            $normalized['not_before_at'] = null;
            $normalized['expires_at'] = null;
            $normalized['fingerprint'] = null;
            $normalized['next_renewal_at'] = null;
        } elseif (!$existing || $existing->source_type !== $source) {
            $normalized['status'] = 'pending';
            $normalized['not_before_at'] = null;
            $normalized['expires_at'] = null;
            $normalized['fingerprint'] = null;
            $normalized['last_renewed_at'] = null;
            $normalized['next_renewal_at'] = null;
        }

        if (!$forSave) {
            unset($normalized['dns_credentials'], $normalized['certificate_content'], $normalized['private_key_content']);
        }
        return $normalized;
    }

    private function secretInputChanged(array $input, ?ServerCertificate $existing, string $key): bool
    {
        if (!$existing || !array_key_exists($key, $input)) {
            return false;
        }
        $value = $input[$key];
        if ($key === 'dns_credentials' && is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        $old = $existing->{$key} ?? null;
        if (is_array($value) || is_array($old)) {
            return $value !== $old;
        }
        return trim((string) $value) !== '' && (string) $value !== (string) $old;
    }

    private function validateSourceRequirements(string $source, array $normalized, array $input, ?ServerCertificate $existing): void
    {
        if (in_array($source, [ServerCertificate::SOURCE_ACME_HTTP, ServerCertificate::SOURCE_ACME_DNS], true)
            && !$normalized['email']) {
            throw new ApiException('ACME 证书必须填写联系邮箱。', 422);
        }
        if ($source === ServerCertificate::SOURCE_ACME_DNS && !$normalized['dns_provider']) {
            throw new ApiException('ACME DNS 证书必须填写 DNS 服务商。', 422);
        }
        $dnsCredentials = $normalized['dns_credentials'] ?? null;
        $hasDnsCredentials = is_array($dnsCredentials)
            ? $dnsCredentials !== []
            : trim((string) $dnsCredentials) !== '';
        if ($source === ServerCertificate::SOURCE_ACME_DNS && !$hasDnsCredentials) {
            throw new ApiException('ACME DNS 证书必须配置 DNS 凭据。', 422);
        }
        if ($source === ServerCertificate::SOURCE_PATH) {
            $certPath = $normalized['certificate_path'];
            $keyPath = $normalized['private_key_path'];
            if (!$this->absolutePath($certPath) || !$this->absolutePath($keyPath)) {
                throw new ApiException('路径证书必须填写证书和私钥的绝对路径。', 422);
            }
        }
        if ($source === ServerCertificate::SOURCE_CONTENT) {
            if (trim((string) ($normalized['certificate_content'] ?? '')) === ''
                || trim((string) ($normalized['private_key_content'] ?? '')) === '') {
                throw new ApiException('PEM 内容证书必须同时填写证书和私钥。', 422);
            }
        }
    }

    private function pemMetadata(string $certificateContent, string $privateKeyContent, array $domains = []): array
    {
        if (!function_exists('openssl_x509_read') || !function_exists('openssl_pkey_get_private')) {
            throw new ApiException('当前 PHP 未启用 OpenSSL，无法校验证书内容。', 500);
        }
        $certificate = @openssl_x509_read($certificateContent);
        $privateKey = @openssl_pkey_get_private($privateKeyContent);
        if (!$certificate || !$privateKey || !@openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new ApiException('PEM 证书和私钥无效，或二者不匹配。', 422);
        }
        $parsed = @openssl_x509_parse($certificate);
        if (!is_array($parsed) || empty($parsed['validFrom_time_t']) || empty($parsed['validTo_time_t'])) {
            throw new ApiException('无法读取 PEM 证书的有效期。', 422);
        }
        $this->assertPemCoversDomains($parsed, $domains);

        return [
            'not_before_at' => CarbonImmutable::createFromTimestamp((int) $parsed['validFrom_time_t']),
            'expires_at' => CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t']),
            'fingerprint' => function_exists('openssl_x509_fingerprint')
                ? 'SHA256:' . strtoupper((string) openssl_x509_fingerprint($certificate, 'sha256'))
                : null,
        ];
    }

    /**
     * A matching key pair alone is not enough: a PEM resource must also be
     * usable for every hostname the panel says it serves.  OpenSSL exposes
     * SANs as a comma-separated extension and older self-signed fixtures may
     * only have a Common Name, so retain the latter as a compatibility
     * fallback.
     */
    private function assertPemCoversDomains(array $parsed, array $domains): void
    {
        if ($domains === []) return;

        $names = [];
        $subjectAltName = $parsed['extensions']['subjectAltName'] ?? '';
        foreach (preg_split('/\s*,\s*/', (string) $subjectAltName, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            [$kind, $value] = array_pad(explode(':', $entry, 2), 2, '');
            if (in_array(strtolower(trim($kind)), ['dns', 'ip address'], true)) {
                $names[] = trim($value);
            }
        }
        $commonName = $parsed['subject']['CN'] ?? null;
        if (is_string($commonName) && trim($commonName) !== '') {
            $names[] = trim($commonName);
        }
        $names = array_values(array_unique(array_map(
            static fn (string $name): string => strtolower(rtrim(trim($name), '.')),
            $names,
        )));

        foreach ($domains as $domain) {
            $requested = strtolower(rtrim(trim((string) $domain), '.'));
            $covered = false;
            foreach ($names as $name) {
                if ($name === $requested) {
                    $covered = true;
                    break;
                }
                // A wildcard certificate covers exactly one label.  Do not
                // let *.example.test silently cover a.b.example.test.
                if (str_starts_with($name, '*.')
                    && !str_starts_with($requested, '*.')
                    && substr_count($requested, '.') === substr_count($name, '.')
                    && str_ends_with($requested, substr($name, 1))) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                throw new ApiException("PEM 证书不覆盖声明的域名：{$domain}。", 422);
            }
        }
    }

    private function ensurePathResource(int $machineId, string $certificatePath, string $privateKeyPath, string $name, string $host): ServerCertificate
    {
        if (!$this->absolutePath($certificatePath) || !$this->absolutePath($privateKeyPath)) {
            throw new ApiException('路径证书必须填写证书和私钥的绝对路径。', 422);
        }
        $certificate = ServerCertificate::query()
            ->where('machine_id', $machineId)
            ->where('source_type', ServerCertificate::SOURCE_PATH)
            ->where('certificate_path', $certificatePath)
            ->where('private_key_path', $privateKeyPath)
            ->first();
        if ($certificate) return $certificate;

        $certificate = new ServerCertificate();
        $certificate->id = (string) Str::uuid();
        $certificate->fill([
            'machine_id' => $machineId,
            'name' => trim($name) !== '' ? trim($name) : '路径证书',
            'source_type' => ServerCertificate::SOURCE_PATH,
            'domains' => [$this->domainFallback($host, $machineId)],
            'auto_renew' => false,
            'certificate_path' => $certificatePath,
            'private_key_path' => $privateKeyPath,
            'revision' => 1,
            'status' => 'pending',
        ]);
        $certificate->save();
        return $certificate;
    }

    private function machineId(array $input): int
    {
        $machineId = (int) ($input['machine_id'] ?? 0);
        if ($machineId < 1 || !ServerMachine::query()->whereKey($machineId)->exists()) {
            throw new ApiException('服务器不存在。', 404);
        }
        return $machineId;
    }

    private function domains(mixed $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);
        $domains = collect($values ?: [])
            ->map(fn ($domain) => trim((string) $domain))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($domains === [] || count($domains) > 50) {
            throw new ApiException('至少需要一个域名，且最多登记 50 个域名。', 422);
        }
        foreach ($domains as $domain) {
            if (strlen($domain) > 253 || str_contains($domain, '/') || preg_match('/\s/', $domain)) {
                throw new ApiException('证书域名格式不正确。', 422);
            }
            if ($domain !== '*' && filter_var($domain, FILTER_VALIDATE_IP) === false
                && !preg_match('/\A(?:\*\.)?[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/', $domain)) {
                throw new ApiException('证书域名格式不正确。', 422);
            }
        }
        return $domains;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        return $value === '' ? null : $value;
    }

    private function absolutePath(?string $path): bool
    {
        return is_string($path) && $path !== '' && strlen($path) <= 2048
            && (str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\/]/', $path) === 1)
            && !str_contains($path, "\0");
    }

    private function dnsEnvironment(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function domainFallback(string $host, int $machineId): string
    {
        $host = trim($host);
        if ($host !== '' && (filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A[A-Za-z0-9.-]+\z/', $host) === 1)) return $host;
        return "machine-{$machineId}.local";
    }

    private function effectiveStatus(ServerCertificate $certificate): string
    {
        if ($certificate->last_error) return 'error';
        if ($certificate->expires_at) {
            if ($certificate->expires_at->isPast()) return 'expired';
            if ($certificate->expires_at->lessThanOrEqualTo(now()->addDays(30))) return 'expiring';
        }
        return (string) ($certificate->status ?: 'pending');
    }
}
