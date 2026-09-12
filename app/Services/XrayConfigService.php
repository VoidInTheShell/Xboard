<?php

namespace App\Services;

use App\Models\Outbound;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Owns the panel side of the native Xray control plane.
 *
 * The value stored in xray_config is deliberately the native Xray object.  A
 * node patch is merged with its machine patch, while arrays remain ordered
 * replacement values.  Legacy custom_* fields are never translated into the
 * native object here; when both representations are present we fail closed.
 */
class XrayConfigService
{
    public const SECTIONS = [
        'inbounds', 'outbounds', 'dns', 'routing', 'log', 'policy', 'stats',
        'metrics', 'reverse', 'observatory', 'burstObservatory', 'fakedns', 'fakeDns',
        'transport',
    ];

    private const LIST_SECTIONS = ['inbounds', 'outbounds', 'fakedns', 'fakeDns'];

    private const INBOUND_PROTOCOLS = [
        'dokodemo-door', 'tunnel', 'mixed', 'vmess', 'vless', 'trojan',
        'shadowsocks', 'socks', 'http', 'hysteria', 'wireguard', 'tun',
    ];

    private const INBOUND_FIELDS = [
        'listen', 'port', 'protocol', 'tag', 'settings', 'streamSettings',
        'sniffing',
    ];

    private const OUTBOUND_PROTOCOLS = [
        'vmess', 'vless', 'trojan', 'shadowsocks', 'socks', 'http', 'hysteria',
        'freedom', 'direct', 'blackhole', 'block', 'dns', 'loopback', 'wireguard',
    ];

    private const OUTBOUND_FIELDS = [
        'tag', 'protocol', 'settings', 'sendThrough', 'targetStrategy',
        'streamSettings', 'proxySettings', 'mux',
    ];

    private const MANAGED_INBOUND_FIELDS = [
        'listen', 'port', 'settings', 'streamSettings', 'sniffing',
    ];

    /** Fields which may be overridden on a server-sourced outbound. */
    private const SOURCE_OVERRIDE_FIELDS = [
        'tag', 'sendThrough', 'targetStrategy', 'streamSettings', 'proxySettings', 'mux',
    ];

    private const SOURCE_TYPES = ['manual', 'server'];

    private const RESOLUTION_MODES = ['pinned', 'live'];

    private const CERT_MODES = ['none', 'http', 'dns', 'self', 'file', 'content'];

    private const CERT_FIELDS = [
        'auto_tls', 'domain', 'email', 'cert_file', 'key_file', 'cert_dir',
        'http_port', 'cert_mode', 'mode', 'dns_provider', 'dns_env',
        'cert_content', 'key_content',
    ];

    public static function supports(Server $node): bool
    {
        return in_array(self::nodeProtocol($node), [
            'vmess', 'vless', 'trojan', 'shadowsocks', 'socks', 'http', 'hysteria',
        ], true);
    }

    /** Editable baseline installed for every newly managed Xray node. */
    public static function defaultNodeConfig(): stdClass
    {
        return json_decode(json_encode([
            'routing' => [
                'rules' => [
                    [
                        'type' => 'field',
                        'inboundTag' => ['api'],
                        'outboundTag' => 'api',
                        'enabled' => true,
                    ],
                    [
                        'type' => 'field',
                        'ip' => ['geoip:cn'],
                        'outboundTag' => 'block',
                        'enabled' => true,
                    ],
                    [
                        'type' => 'field',
                        'domain' => [
                            'geosite:cn',
                            'domain:googleapis.cn',
                            'domain:google.cn',
                            'geosite:google-play@cn',
                            'domain:ping0.cc',
                        ],
                        'outboundTag' => 'block',
                        'enabled' => true,
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Raise a machine-addressable validation response.
     *
     * Older callers only supplied a sentence and older clients only looked
     * for the aggregate xray_config key.  Keep that compatibility key while
     * deriving a more useful canonical path whenever the sentence contains
     * one.  New validation code should use failAt() so that the path never
     * depends on message parsing.
     */
    public static function fail(string $message, ?string $path = null, ?string $compatibilityPath = 'xray_config'): never
    {
        $path ??= self::inferErrorPath($message) ?? $compatibilityPath ?? 'xray_config';
        $localized = self::localizeValidationMessage($message);
        $errors = [$path => [$localized]];
        if ($compatibilityPath !== null && $compatibilityPath !== $path) {
            $errors[$compatibilityPath] = ['配置检查未通过，请修正标记字段后重试。'];
        }
        throw ValidationException::withMessages($errors);
    }

    public static function failAt(string $path, string $message, ?string $compatibilityPath = 'xray_config'): never
    {
        self::fail($message, $path, $compatibilityPath);
    }

    private static function inferErrorPath(string $message): ?string
    {
        if (preg_match(
            '/\b((?:xray_config|client_settings|outbound_bindings|cert_config|config|source_node_id|machine_id|node_id)(?:\.[A-Za-z0-9_-]+)*)\b/',
            $message,
            $match,
        )) {
            return $match[1];
        }
        return null;
    }

    private static function localizeValidationMessage(string $message): string
    {
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $message)) return $message;
        $lower = strtolower($message);
        return match (true) {
            str_contains($lower, 'must be a json object') => '必须是 JSON 对象。',
            str_contains($lower, 'must be an ordered json array') => '必须是有序 JSON 数组。',
            str_contains($lower, 'must be a string') => '必须是字符串。',
            str_contains($lower, 'must be boolean') => '必须是布尔值。',
            str_contains($lower, 'must be a positive integer') => '必须是正整数。',
            str_contains($lower, 'must be one of') => '取值不在允许范围内。',
            str_contains($lower, 'is not supported') => '该字段或协议暂不支持。',
            str_contains($lower, 'is controlled by xboard') => '该字段由节点配置管理，不能在此修改。',
            str_contains($lower, 'cannot be used') => '该配置组合不可用。',
            str_contains($lower, 'does not exist') => '引用的目标不存在。',
            str_contains($lower, 'cycle') => '配置引用不能形成循环。',
            str_contains($lower, 'requires') => '缺少必需配置。',
            default => '配置字段或取值不符合要求。',
        };
    }

    /** Convert a request object/array into an independent native object. */
    public static function object(mixed $value, string $field = 'value'): stdClass
    {
        if ($value instanceof stdClass) {
            $copy = self::cloneValue($value);
            return $copy instanceof stdClass ? $copy : new stdClass();
        }
        // Laravel's request bag represents an empty JSON object as [] when
        // associative decoding is used. At the root of a native config that
        // is still an unambiguous valid empty object.
        if (is_array($value) && ($value === [] || !array_is_list($value))) {
            $copy = self::cloneValue($value);
            return self::arrayToObject($copy);
        }
        self::fail("{$field} must be a JSON object");
    }

    /** Deep merge objects; arrays and scalar values are replaced as a unit. */
    public static function merge(stdClass $base, stdClass $override): stdClass
    {
        $result = self::withoutTombstones(self::cloneValue($base));
        foreach (get_object_vars($override) as $key => $value) {
            if ($value === null) {
                unset($result->$key);
                continue;
            }
            $result->$key = $value instanceof stdClass && ($result->$key ?? null) instanceof stdClass
                ? self::merge($result->$key, $value)
                : self::cloneValue($value);
        }
        return $result;
    }

    /** Alias that makes the merge policy explicit at call sites. */
    public static function mergeConfig(stdClass $base, stdClass $override): stdClass
    {
        return self::merge($base, $override);
    }

    public static function normalizeSourceType(?string $sourceType): string
    {
        $sourceType = strtolower(trim((string) ($sourceType ?: Outbound::SOURCE_MANUAL)));
        return match ($sourceType) {
            '', 'manual', 'native' => Outbound::SOURCE_MANUAL,
            'server', 'node', 'existing_node', 'existing-server' => Outbound::SOURCE_NODE,
            default => self::invalidValue('source_type', $sourceType, self::SOURCE_TYPES),
        };
    }

    public static function normalizeResolutionMode(?string $mode): string
    {
        $mode = strtolower(trim((string) ($mode ?: Outbound::RESOLUTION_PINNED)));
        return match ($mode) {
            '', 'pinned', 'snapshot' => Outbound::RESOLUTION_PINNED,
            'live', 'dynamic' => Outbound::RESOLUTION_LIVE,
            default => self::invalidValue('resolution_mode', $mode, self::RESOLUTION_MODES),
        };
    }

    /**
     * Normalize the small, public VLESS client settings accepted alongside a
     * native xray_config patch.  Server-side decryption is intentionally not
     * part of this object; it can only arrive through the native inbound
     * object and is never copied into an error or a client-facing response.
     */
    public static function normalizeClientSettings(mixed $value): stdClass
    {
        $settings = self::object($value, 'client_settings');
        foreach (get_object_vars($settings) as $field => $item) {
            if (!in_array($field, ['flow', 'encryption'], true)) {
                self::fail("client_settings.{$field} is not supported");
            }
            if ($field === 'flow') {
                if ($item !== null && !is_string($item)) {
                    self::fail('client_settings.flow must be a string or null');
                }
                continue;
            }
            if ($item === null) {
                continue;
            }
            if (is_string($item)) {
                $settings->encryption = (object) ['enabled' => true, 'encryption' => $item];
                continue;
            }
            if (!$item instanceof stdClass) {
                self::fail('client_settings.encryption must be an object, string, or null');
            }
            foreach (get_object_vars($item) as $key => $publicValue) {
                if (!in_array($key, ['enabled', 'encryption'], true)) {
                    self::fail("client_settings.encryption.{$key} is not supported");
                }
                if ($key === 'enabled' && !is_bool($publicValue)) {
                    self::fail('client_settings.encryption.enabled must be boolean');
                }
                if ($key === 'encryption' && $publicValue !== null && !is_string($publicValue)) {
                    self::fail('client_settings.encryption.encryption must be a string or null');
                }
            }
        }
        return $settings;
    }

    /**
     * Validate the sparse patch accepted for an outbound sourced from another
     * node.  Endpoint/account settings are generated from the source node and
     * its dedicated credential; only these identity-free native fields may be
     * edited.  A null value removes an existing override at that level.
     */
    public static function validateSourceOverride(mixed $value, string $field = 'config_patch'): stdClass
    {
        $patch = self::object($value, $field);
        foreach (get_object_vars($patch) as $key => $item) {
            if (!in_array($key, self::SOURCE_OVERRIDE_FIELDS, true)) {
                self::fail("{$field}.{$key} cannot be edited on a source outbound");
            }
            if ($item === null) {
                continue;
            }
            if (in_array($key, ['tag', 'sendThrough', 'targetStrategy'], true)) {
                if (!is_string($item) || trim($item) === '') {
                    self::fail("{$field}.{$key} must be a non-empty string or null");
                }
                continue;
            }
            if (!$item instanceof stdClass) {
                self::fail("{$field}.{$key} must be a JSON object or null");
            }
        }
        return $patch;
    }

    /**
     * Validate the panel-editable native object.  Protocol/tag and user
     * account fields of the one managed inbound remain owned by XBoard.
     */
    public static function validate(stdClass $config, ?Server $node = null, bool $references = true): void
    {
        foreach (get_object_vars($config) as $section => $value) {
            if (!in_array($section, self::SECTIONS, true)) {
                self::fail("Unknown Xray configuration section '{$section}'");
            }
            $isList = in_array($section, self::LIST_SECTIONS, true);
            // A null section is an explicit inheritance tombstone. It is
            // stored as part of the instance override and removed before
            // producing the effective configuration for Node.
            if ($value === null) {
                continue;
            }
            if ($isList ? !is_array($value) : !$value instanceof stdClass) {
                self::fail("xray_config.{$section} must be a JSON " . ($isList ? 'array' : 'object'));
            }
            if ($section === 'transport' && get_object_vars($value) !== []) {
                self::fail('xray_config.transport is not supported by the Xray control plane; leave it empty');
            }
        }

        if (property_exists($config, 'inbounds') && $config->inbounds !== null) {
            if (count($config->inbounds) < 1 || !$config->inbounds[0] instanceof stdClass) {
                self::fail('xray_config.inbounds must start with the managed inbound override');
            }
            self::validateManagedInbound($config->inbounds[0], $node);
            $inboundTags = [];
            if ($node) {
                $inboundTags[strtolower(self::nodeProtocol($node) . '-in')] = true;
            } elseif (is_string($config->inbounds[0]->tag ?? null)) {
                $inboundTags[strtolower(trim($config->inbounds[0]->tag))] = true;
            }
            foreach (array_slice($config->inbounds, 1) as $index => $inbound) {
                self::validateIndependentInbound($inbound, $index + 1, $inboundTags);
            }
        }

        if (($config->policy ?? null) instanceof stdClass) {
            self::validatePolicy($config->policy);
        }

        if ($config->metrics ?? null) {
            if (property_exists($config->metrics, 'tag') && !is_string($config->metrics->tag)) {
                self::fail('xray_config.metrics.tag must be a string');
            }
        }

        // `api` is a panel-only maintenance target. It is displayed with the
        // native rule list for familiar Xray semantics, then removed from the
        // runtime payload because Xboard-Node embeds the core and reads stats
        // directly instead of exposing Xray's gRPC API listener.
        $knownTags = ['direct', 'block', 'api'];
        $graph = [];
        $seen = [];

        if (property_exists($config, 'reverse') && $config->reverse instanceof stdClass) {
            self::validateReverse($config->reverse, $knownTags);
        }
        if (property_exists($config, 'metrics') && $config->metrics instanceof stdClass && is_string($config->metrics->tag ?? null)) {
            $knownTags[] = $config->metrics->tag;
        }

        if (property_exists($config, 'outbounds') && $config->outbounds !== null) {
            foreach ($config->outbounds as $index => $outbound) {
                self::validateOutbound($outbound, $index);
                $tag = $outbound->tag;
                $tagKey = strtolower($tag);
                if (isset($seen[$tagKey])) {
                    self::failAt("xray_config.outbounds.{$index}.tag", "出站标记 '{$tag}' 已重复。");
                }
                $seen[$tagKey] = true;
                $knownTags[] = $tag;

                $next = $outbound->proxySettings->tag
                    ?? $outbound->streamSettings->sockopt->dialerProxy
                    ?? null;
                if ($next !== null) {
                    if (!is_string($next) || trim($next) === '') {
                        self::fail("xray_config.outbounds.{$index} chain target must be a tag");
                    }
                    $graph[$tag] = $next;
                }
            }
        }

        if ($node) {
            if (property_exists($config, 'outbounds') && $config->outbounds !== null && !empty($node->custom_outbounds)) {
                self::fail('Native outbounds cannot be used while legacy outbound settings are present');
            }
            if (property_exists($config, 'routing') && $config->routing !== null
                && (!empty($node->custom_routes) || !empty($node->route_ids))) {
                self::fail('Native routing cannot be used while legacy route settings are present');
            }
        }

        if (!$references) {
            return;
        }

        foreach ($graph as $tag => $next) {
            $visited = [$tag => true];
            while ($next !== null) {
                if (!in_array($next, $knownTags, true)) {
                    self::fail("xray_config chain target '{$next}' does not exist");
                }
                if (isset($visited[$next])) {
                    self::fail("xray_config outbound chain contains a cycle at '{$next}'");
                }
                $visited[$next] = true;
                $next = $graph[$next] ?? null;
            }
        }

        if (property_exists($config, 'routing') && $config->routing instanceof stdClass) {
            self::validateRouting($config->routing, $knownTags);
        }
    }

    /**
     * Validate a node change against an unsaved model clone.
     *
     * This is deliberately the same effective merge and VLESS reconciliation
     * used by the save path.  It returns only an in-memory result so callers
     * can expose a preflight endpoint without changing revisions or columns.
     * A null config/binding value means "use the currently persisted value";
     * callers can still pass an explicit empty object/array to clear it.
     *
     * @return array{node: Server, effective: stdClass, config_revision: int, config_hash: string}
     */
    public static function preflightNode(
        Server $node,
        ?stdClass $config = null,
        ?array $bindings = null,
        ?stdClass $clientSettings = null,
        mixed $certificate = null,
        bool $certificateProvided = false,
    ): array {
        $candidate = clone $node;
        if ($config !== null) {
            $candidate->setAttribute('xray_config', self::cloneValue($config));
        }
        if ($bindings !== null) {
            $candidate->setAttribute('outbound_bindings', self::cloneValue($bindings));
        }
        if ($certificateProvided) {
            $candidate->setAttribute(
                'cert_config',
                $certificate === null
                    ? null
                    : self::objectToArray(self::object($certificate, 'cert_config')),
            );
        }

        self::validateCertificateConfig($candidate->cert_config, 'cert_config');
        $previousEffective = self::effective($node);
        $effective = self::effective($candidate);
        self::synchronizeVlessRuntimeSettings(
            $candidate,
            $effective,
            $clientSettings,
            $previousEffective,
        );
        $effective = self::effective($candidate);
        self::validateManagedEndpoint(self::effectiveInbound(self::managedInbound($candidate), $effective));
        self::validateRuntimeRequirements($candidate, $effective);

        return [
            'node' => $candidate,
            'effective' => $effective,
            'config_revision' => (int) ($node->config_revision ?? 0),
            'config_hash' => self::hash($effective),
        ];
    }

    /**
     * Validate a proposed machine default against every attached node in
     * memory.  The machine's own sections are structurally checked first;
     * node-specific checks then run after the normal inheritance merge.
     *
     * @return array{machine_id: int, affected_nodes: array<int, array{node_id:int, config_revision:int, config_hash:string}>}
     */
    public static function preflightMachine(ServerMachine $machine, stdClass $config): array
    {
        self::validate($config, null, false);
        $candidateMachine = clone $machine;
        $candidateMachine->setAttribute('xray_config', self::cloneValue($config));

        $nodes = $machine->relationLoaded('servers')
            ? $machine->servers
            : $machine->servers()->get();
        $affected = [];
        foreach ($nodes as $node) {
            $candidate = clone $node;
            $candidate->setRelation('machine', $candidateMachine);
            $candidate->setAttribute('machine_id', $machine->id);
            self::validateCertificateConfig($candidate->cert_config, 'cert_config');
            $effective = self::effective($candidate);
            self::synchronizeVlessRuntimeSettings(
                $candidate,
                $effective,
                null,
                self::effective($node),
            );
            $effective = self::effective($candidate);
            self::validateManagedEndpoint(self::effectiveInbound(self::managedInbound($candidate), $effective));
            self::validateRuntimeRequirements($candidate, $effective);
            $affected[] = [
                'node_id' => (int) $node->id,
                'config_revision' => (int) ($node->config_revision ?? 0),
                'config_hash' => self::hash($effective),
            ];
        }

        return [
            'machine_id' => (int) $machine->id,
            'affected_nodes' => $affected,
        ];
    }

    /**
     * Check the certificate fields understood by Node.  This is a static
     * preflight only: certificate files and key material are ultimately
     * opened and checked by Node at runtime.
     */
    public static function validateCertificateConfig(mixed $value, string $path = 'cert_config'): ?array
    {
        if ($value === null) return null;
        $config = self::objectToArray(self::object($value, $path));
        if (!is_array($config)) {
            self::failAt($path, '证书配置必须是对象。');
        }

        foreach ($config as $field => $item) {
            if (!in_array($field, self::CERT_FIELDS, true)) {
                self::failAt("{$path}.{$field}", '证书字段不受支持。');
            }
            if ($item === null) continue;
            if (in_array($field, ['auto_tls'], true) && !is_bool($item)) {
                self::failAt("{$path}.{$field}", '必须是布尔值。');
            }
            if (in_array($field, ['http_port'], true)
                && (!is_int($item) && !(is_string($item) && ctype_digit(trim($item))))) {
                self::failAt("{$path}.{$field}", '必须是整数。');
            }
            if (in_array($field, ['dns_env'], true) && !is_array($item)) {
                self::failAt("{$path}.{$field}", '必须是对象。');
            }
            if (in_array($field, ['domain', 'email', 'cert_file', 'key_file', 'cert_dir', 'cert_mode', 'mode', 'dns_provider', 'cert_content', 'key_content'], true)
                && !is_string($item)) {
                self::failAt("{$path}.{$field}", '必须是字符串。');
            }
        }

        $rawMode = array_key_exists('cert_mode', $config) ? $config['cert_mode'] : ($config['mode'] ?? null);
        if ($rawMode !== null && !is_string($rawMode)) {
            self::failAt("{$path}.cert_mode", '证书模式必须是字符串。');
        }
        $mode = strtolower(trim((string) ($rawMode ?? '')));
        $certFile = self::nonEmptyString($config['cert_file'] ?? null);
        $keyFile = self::nonEmptyString($config['key_file'] ?? null);
        $certContent = self::nonEmptyString($config['cert_content'] ?? null);
        $keyContent = self::nonEmptyString($config['key_content'] ?? null);

        if ($mode === '') {
            $mode = ($certContent !== null || $keyContent !== null)
                ? 'content'
                : (($certFile !== null || $keyFile !== null)
                    ? 'file'
                    : ((bool) ($config['auto_tls'] ?? false) ? 'http' : 'none'));
        }
        if (!in_array($mode, self::CERT_MODES, true)) {
            self::failAt("{$path}.cert_mode", '证书模式必须是 none、http、dns、self、file 或 content。');
        }

        self::requireCertificatePair($certFile, $keyFile, "{$path}.cert_file", "{$path}.key_file");
        self::requireCertificatePair($certContent, $keyContent, "{$path}.cert_content", "{$path}.key_content");

        if ($mode === 'file') {
            if ($certFile === null) self::failAt("{$path}.cert_file", 'file 模式必须填写证书文件路径。');
            if ($keyFile === null) self::failAt("{$path}.key_file", 'file 模式必须填写私钥文件路径。');
        }
        if ($mode === 'content') {
            if ($certContent === null) self::failAt("{$path}.cert_content", 'content 模式必须填写证书内容。');
            if ($keyContent === null) self::failAt("{$path}.key_content", 'content 模式必须填写私钥内容。');
            if (!str_contains($certContent, '-----BEGIN CERTIFICATE-----')
                || !str_contains($certContent, '-----END CERTIFICATE-----')) {
                self::failAt("{$path}.cert_content", '证书内容必须包含完整 PEM 证书。');
            }
            if (!preg_match('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/', $keyContent)
                || !preg_match('/-----END [A-Z0-9 ]*PRIVATE KEY-----/', $keyContent)) {
                self::failAt("{$path}.key_content", '私钥内容必须包含完整 PEM 私钥。');
            }
        }

        if (in_array($mode, ['http', 'dns'], true)
            && self::nonEmptyString($config['domain'] ?? null) === null) {
            self::failAt("{$path}.domain", '自动签发证书必须填写域名。');
        }
        if ($mode === 'dns'
            && self::nonEmptyString($config['dns_provider'] ?? null) === null) {
            self::failAt("{$path}.dns_provider", 'DNS 签发模式必须填写 DNS 服务商。');
        }
        if ($mode === 'dns' && (!isset($config['dns_env']) || !is_array($config['dns_env']) || $config['dns_env'] === [])) {
            self::failAt("{$path}.dns_env", 'DNS 签发模式必须填写环境变量。');
        }

        return $config;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function requireCertificatePair(
        ?string $first,
        ?string $second,
        string $firstPath,
        string $secondPath,
    ): void {
        if (($first === null) === ($second === null)) return;
        if ($first === null) self::failAt($firstPath, '证书和私钥必须成对填写。');
        self::failAt($secondPath, '证书和私钥必须成对填写。');
    }

    private static function validateRuntimeRequirements(Server $node, stdClass $effective): void
    {
        if (self::nodeProtocol($node) !== 'hysteria') return;
        if (!self::nodeHasCertificate($node)) {
            self::failAt('cert_config.cert_mode', 'Hysteria2 启用 TLS 时必须配置证书。');
        }
    }

    public static function validateOutbound(mixed $outbound, int|string|null $index = null): void
    {
        $prefix = $index === null ? 'config' : "xray_config.outbounds.{$index}";
        if (!$outbound instanceof stdClass
            || !is_string($outbound->tag ?? null)
            || trim($outbound->tag) === ''
            || !is_string($outbound->protocol ?? null)
            || trim($outbound->protocol) === '') {
            self::fail("{$prefix} requires a non-empty tag and protocol");
        }

        $protocol = strtolower(trim($outbound->protocol));
        if (!in_array($protocol, self::OUTBOUND_PROTOCOLS, true)) {
            self::fail("{$prefix}.protocol '{$outbound->protocol}' is not supported by the Xray control plane");
        }
        $tag = strtolower(trim($outbound->tag));
        if (in_array($tag, ['api', 'blocked'], true)) {
            self::fail("{$prefix}.tag '{$outbound->tag}' is reserved");
        }
        foreach (get_object_vars($outbound) as $field => $value) {
            if (!in_array($field, self::OUTBOUND_FIELDS, true)) {
                self::fail("{$prefix}.{$field} is not a supported native outbound field");
            }
        }
        foreach (['settings', 'streamSettings', 'proxySettings', 'mux'] as $field) {
            if (property_exists($outbound, $field) && !$outbound->$field instanceof stdClass) {
                self::fail("{$prefix}.{$field} must be a JSON object");
            }
        }
        if ($tag === 'direct' && !in_array($protocol, ['freedom', 'direct'], true)) {
            self::fail("{$prefix} tag direct must use freedom");
        }
        if ($tag === 'block' && !in_array($protocol, ['blackhole', 'block'], true)) {
            self::fail("{$prefix} tag block must use blackhole");
        }
        $proxyTag = $outbound->proxySettings->tag ?? null;
        $dialerProxy = $outbound->streamSettings->sockopt->dialerProxy ?? null;
        if ($proxyTag !== null && $dialerProxy !== null) {
            self::fail("{$prefix} cannot set both proxySettings.tag and streamSettings.sockopt.dialerProxy");
        }
        if ($proxyTag !== null && !is_string($proxyTag)) {
            self::fail("{$prefix}.proxySettings.tag must be a tag string");
        }
        if ($dialerProxy !== null && !is_string($dialerProxy)) {
            self::fail("{$prefix}.streamSettings.sockopt.dialerProxy must be a tag string");
        }
    }

    /** Normalize and validate an instance's ordered candidate bindings. */
    public static function normalizeBindings(mixed $bindings): array
    {
        if (!is_array($bindings) || !array_is_list($bindings)) {
            self::fail('outbound_bindings must be an ordered JSON array');
        }
        $result = [];
        $tags = [];
        foreach ($bindings as $index => $binding) {
            if ($binding instanceof stdClass) {
                $binding = get_object_vars($binding);
            }
            if (!is_array($binding)) {
                self::fail("outbound_bindings.{$index} must be an object");
            }
            $id = $binding['outbound_id'] ?? null;
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
                self::fail("outbound_bindings.{$index}.outbound_id must be a positive integer");
            }
            $normalized = ['outbound_id' => (int) $id, 'enabled' => true];
            if (array_key_exists('enabled', $binding)) {
                if (!is_bool($binding['enabled'])) {
                    self::fail("outbound_bindings.{$index}.enabled must be boolean");
                }
                $normalized['enabled'] = $binding['enabled'];
            }
            if (array_key_exists('tag', $binding) && $binding['tag'] !== null) {
                if (!is_string($binding['tag']) || trim($binding['tag']) === '') {
                    self::fail("outbound_bindings.{$index}.tag must be a non-empty string");
                }
                $tag = trim($binding['tag']);
                if (in_array(strtolower($tag), ['api', 'blocked', 'direct', 'block'], true)) {
                    self::fail("outbound_bindings.{$index}.tag is reserved");
                }
                if (isset($tags[strtolower($tag)])) {
                    self::failAt("outbound_bindings.{$index}.tag", "出站标记 '{$tag}' 已重复。");
                }
                $tags[strtolower($tag)] = true;
                $normalized['tag'] = $tag;
            }
            $result[] = $normalized;
        }
        return $result;
    }

    /**
     * Build the effective native config for the node.  Bindings are an
     * explicit source of the ordered outbounds; native outbounds and
     * bindings cannot silently compete.
     */
    public static function effective(Server $node, array $candidateOverrides = []): stdClass
    {
        if (!self::supports($node)) {
            if ($node->xray_config !== null || $node->outbound_bindings !== null) {
                self::fail('This instance protocol is not available in the Xray control plane');
            }
            return new stdClass();
        }

        $machineConfig = $node->machine_id && $node->relationLoaded('machine')
            ? ($node->machine?->xray_config ?? new stdClass())
            : ($node->machine_id ? ($node->machine?->xray_config ?? new stdClass()) : new stdClass());
        $config = self::mergeConfig(
            $machineConfig instanceof stdClass ? $machineConfig : self::object($machineConfig),
            $node->xray_config instanceof stdClass ? $node->xray_config : new stdClass(),
        );

        // An empty list is meaningful only when no native outbounds exist: it
        // clears all user-selected candidates and leaves system actions. When
        // native outbounds are present, the UI's default [] means "no binding
        // source" and native ordering remains authoritative.
        $bindingsAreEmpty = is_array($node->outbound_bindings) && $node->outbound_bindings === [];
        if ($node->outbound_bindings !== null && !($bindingsAreEmpty && property_exists($config, 'outbounds'))) {
            if (property_exists($config, 'outbounds')) {
                self::fail('Use either native outbounds or ordered outbound bindings for an instance');
            }
            $config->outbounds = [];
            foreach (self::normalizeBindings($node->outbound_bindings) as $binding) {
                if (($binding['enabled'] ?? true) === false) {
                    continue;
                }
                $candidate = self::candidateById($binding['outbound_id'], $candidateOverrides);
                if (!$candidate || !$candidate->enabled) {
                    self::fail("Outbound candidate {$binding['outbound_id']} is unavailable");
                }
                self::assertCandidateGraph($candidate, [], $candidateOverrides);
                $outbound = self::resolveCandidate($candidate, $node, $candidateOverrides);
                if (isset($binding['tag'])) {
                    $outbound->tag = $binding['tag'];
                }
                self::validateOutbound($outbound, count($config->outbounds));
                $config->outbounds[] = $outbound;
            }
        }

        self::maintainPanelApiRule($config);
        self::appendSystemOutbounds($config);
        self::selectDefaultOutbound($config, $node);
        self::validate($config, $node);
        self::removeMatchlessFieldRules($config);
        return $config;
    }

    /**
     * Return the exact native patch sent to Xboard-Node. Disabled rules and the
     * panel-only api maintenance rule must never reach Xray's strict schema.
     */
    public static function runtime(Server $node, array $candidateOverrides = []): stdClass
    {
        $config = self::cloneValue(self::effective($node, $candidateOverrides));
        $routing = $config->routing ?? null;
        if (!$routing instanceof stdClass || !is_array($routing->rules ?? null)) {
            return $config;
        }

        $rules = [];
        foreach ($routing->rules as $rule) {
            if (!$rule instanceof stdClass
                || self::isPanelApiRule($rule)
                || self::isMatchlessFieldRule($rule)) {
                continue;
            }
            if (property_exists($rule, 'enabled') && $rule->enabled === false) {
                continue;
            }
            unset($rule->enabled);
            $rules[] = $rule;
        }
        $routing->rules = $rules;
        return $config;
    }

    public static function hash(stdClass $config): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if ($value instanceof stdClass) {
                $fields = get_object_vars($value);
                ksort($fields);
                $result = new stdClass();
                foreach ($fields as $key => $item) {
                    $result->$key = $canonical($item);
                }
                return $result;
            }
            if (is_array($value)) {
                return array_map($canonical, $value);
            }
            return $value;
        };
        return hash('sha256', json_encode(
            $canonical($config),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    public static function defaultOutboundTag(stdClass $config): string
    {
        if (!property_exists($config, 'outbounds') || !is_array($config->outbounds)) {
            return 'direct';
        }
        foreach ($config->outbounds as $outbound) {
            if ($outbound instanceof stdClass && is_string($outbound->tag ?? null)) {
                return $outbound->tag;
            }
        }
        return 'direct';
    }

    /** Full desired/effective/application snapshot used by the admin UI. */
    public static function snapshot(Server $node): array
    {
        $effective = self::effective($node);
        $managedInbound = self::managedInbound($node);
        $effectiveInbound = self::effectiveInbound($managedInbound, $effective);
        $application = $node->xray_apply;
        if (!$application instanceof stdClass) {
            $application = Cache::get("xray_config_apply:{$node->id}");
        }
        return [
            'node_id' => (int) $node->id,
            'machine_id' => $node->machine_id ? (int) $node->machine_id : null,
            'xray_config' => $node->xray_config instanceof stdClass ? $node->xray_config : new stdClass(),
            'machine_defaults' => $node->machine_id
                ? ($node->machine?->xray_config instanceof stdClass ? $node->machine->xray_config : new stdClass())
                : new stdClass(),
            'effective_config' => $effective,
            // These are the generated listener fields that the node owns.
            // User accounts/certificates are intentionally omitted; the
            // effective native patch remains separate from this baseline.
            // The admin editor needs the native decryption value to preserve
            // an existing VLESS listener on a no-op save.  It is never copied
            // into client_settings, subscription projections, or validation
            // errors; this is an authenticated configuration snapshot.
            'managed_inbound' => $managedInbound,
            'effective_inbound' => $effectiveInbound,
            'client_settings' => self::clientSettingsSnapshot($node),
            'config_revision' => (int) ($node->config_revision ?? 0),
            'config_hash' => self::hash(self::runtime($node)),
            'default_outbound_tag' => (string) ($node->default_outbound_tag ?: 'direct'),
            'application' => self::applicationReport($application),
            // null means the instance has no binding source; [] is retained
            // as an explicit empty ordered source for instances without
            // native outbounds. The distinction matters to default handling.
            'outbound_bindings' => $node->outbound_bindings,
        ];
    }

    /** Generated inbound baseline shared by the panel NodeConfig contract. */
    public static function managedInbound(Server $node): stdClass
    {
        $protocol = self::nodeProtocol($node);
        $settings = $node->protocol_settings ?? [];
        $inbound = (object) [
            'tag' => $protocol . '-in',
            'listen' => '0.0.0.0',
            'port' => (int) $node->server_port,
            'protocol' => $protocol,
            'streamSettings' => new stdClass(),
        ];

        // Keep only non-user-owned settings in the baseline.  Clients,
        // accounts and certificate private material are created by Node.
        $safeSettings = new stdClass();
        switch ($protocol) {
            case 'vless':
                $safeSettings->decryption = data_get($settings, 'encryption.enabled')
                    ? (string) data_get($settings, 'encryption.decryption', 'none')
                    : 'none';
                $safeSettings->flow = (string) data_get($settings, 'flow', '');
                break;
            case 'shadowsocks':
                if (($cipher = data_get($settings, 'cipher')) !== null) $safeSettings->method = $cipher;
                $safeSettings->network = 'tcp,udp';
                break;
            case 'socks':
                $safeSettings->auth = 'password';
                $safeSettings->udp = true;
                break;
            case 'hysteria':
                $safeSettings->version = (int) data_get($settings, 'version', 2);
                break;
        }
        if (get_object_vars($safeSettings) !== []) $inbound->settings = $safeSettings;

        $stream = self::inboundStreamSettings($node);
        foreach (get_object_vars($stream) as $key => $value) {
            if ($key === 'sockopt') {
                $inbound->streamSettings = self::merge($inbound->streamSettings, (object) ['sockopt' => $value]);
            } else {
                $inbound->streamSettings->$key = $value;
            }
        }
        return $inbound;
    }

    private static function effectiveInbound(stdClass $managed, stdClass $effective): stdClass
    {
        if (!property_exists($effective, 'inbounds') || !is_array($effective->inbounds)
            || !isset($effective->inbounds[0]) || !$effective->inbounds[0] instanceof stdClass) {
            return $managed;
        }
        $result = self::merge($managed, $effective->inbounds[0]);
        // The projection must never expose a null or user-account array.
        if (isset($result->settings) && $result->settings instanceof stdClass) {
            unset($result->settings->clients, $result->settings->accounts, $result->settings->auth);
        }
        $result->protocol = $managed->protocol;
        $result->tag = $managed->tag;
        return $result;
    }

    /** Public VLESS values accepted by the save endpoint, never decryption. */
    private static function clientSettingsSnapshot(Server $node): ?stdClass
    {
        if (self::nodeProtocol($node) !== 'vless') {
            return null;
        }
        $settings = self::projectedProtocolSettings($node);
        $encryption = is_array($settings['encryption'] ?? null) ? $settings['encryption'] : [];
        return (object) [
            'flow' => array_key_exists('flow', $settings) ? $settings['flow'] : null,
            'encryption' => (object) [
                'enabled' => (bool) ($encryption['enabled'] ?? false),
                'encryption' => ($encryption['enabled'] ?? false) === true
                    ? ($encryption['encryption'] ?? null)
                    : null,
            ],
        ];
    }

    /**
     * Return the public VLESS values saved alongside a native inbound.
     *
     * This is deliberately a separate column from protocol_settings.  The
     * latter is the legacy node publication baseline and also feeds the
     * generated managed inbound.  Writing a native decryption or flow into
     * that baseline would make a later native override removal sticky.
     * Older rows have no companion value, so their public encryption remains
     * readable from the legacy publication fields until the next native save.
     */
    private static function storedClientSettings(Server $node): ?stdClass
    {
        if ($node->xray_client_settings instanceof stdClass) {
            return self::normalizeClientSettings($node->xray_client_settings);
        }

        $settings = $node->protocol_settings;
        $legacy = is_array($settings) && is_array($settings['encryption'] ?? null)
            ? $settings['encryption']
            : [];
        if ($legacy === []) {
            return null;
        }

        $public = array_key_exists('encryption', $legacy)
            ? ($legacy['encryption'] === null ? null : (string) $legacy['encryption'])
            : null;
        return (object) [
            'encryption' => (object) [
                'enabled' => (bool) ($legacy['enabled'] ?? false),
                'encryption' => ($legacy['enabled'] ?? false) === true ? $public : null,
            ],
        ];
    }

    private static function storedClientEncryption(Server $node): array
    {
        $clientSettings = self::storedClientSettings($node);
        $encryption = $clientSettings?->encryption;
        if (!$encryption instanceof stdClass) {
            return [];
        }
        return [
            'enabled' => property_exists($encryption, 'enabled')
                ? (bool) $encryption->enabled
                : false,
            'encryption' => property_exists($encryption, 'encryption')
                ? ($encryption->encryption === null ? null : trim((string) $encryption->encryption))
                : null,
        ];
    }

    /**
     * Find an explicitly supplied native VLESS decryption override.  A
     * missing value and an explicit null have different meanings: the former
     * inherits the legacy baseline, while the latter is a native tombstone
     * that disables it.  Node-level settings take precedence over machine
     * defaults, matching the normal native config merge order.
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function explicitNativeDecryption(Server $node): array
    {
        $configs = [];
        if ($node->machine_id) {
            $configs[] = $node->machine?->xray_config;
        }
        $configs[] = $node->xray_config;

        $found = false;
        $value = null;
        foreach ($configs as $config) {
            if (!$config instanceof stdClass || !property_exists($config, 'inbounds')
                || !is_array($config->inbounds) || !isset($config->inbounds[0])
                || !$config->inbounds[0] instanceof stdClass) {
                continue;
            }
            $inboundSettings = $config->inbounds[0]->settings ?? null;
            if ($inboundSettings instanceof stdClass && property_exists($inboundSettings, 'decryption')) {
                $found = true;
                $value = $inboundSettings->decryption;
            }
        }

        return [$found, $value];
    }

    /**
     * Project the effective managed listener into the legacy fields consumed
     * by subscription renderers.  This is an in-memory view only: native
     * xray_config remains the sole persisted source of truth, while the
     * published host/port continue to come from Server rather than listen.
     */
    public static function projectedProtocolSettings(Server $node): array
    {
        $settings = $node->protocol_settings;
        if (!is_array($settings)) $settings = [];
        if (!self::supports($node)) return $settings;

        $hasNative = $node->xray_config !== null
            || ($node->machine_id && $node->machine?->xray_config !== null);
        if (!$hasNative) return $settings;

        $effective = self::effective($node);
        $inbound = self::effectiveInbound(self::managedInbound($node), $effective);
        $protocol = self::nodeProtocol($node);
        $stream = $inbound->streamSettings ?? null;
        $nativeSettings = $inbound->settings ?? null;

        if ($protocol === 'shadowsocks' && $nativeSettings instanceof stdClass) {
            if (property_exists($nativeSettings, 'method') && is_string($nativeSettings->method)) {
                $settings['cipher'] = $nativeSettings->method;
            }
            // The native SS listener's network is carried in settings, not in
            // streamSettings.  It has no corresponding subscription field.
            unset($settings['network'], $settings['network_settings']);
        } elseif (in_array($protocol, ['vmess', 'vless', 'trojan'], true)) {
            if ($protocol === 'vless') {
                // Native decryption is not a publication value.  The public
                // client half is stored separately and is projected only
                // while an explicit native decryption override is present.
                // If that override is removed outside the save endpoint, the
                // legacy pair remains the safe source of truth as well.
                [$nativeDecryptionProvided] = self::explicitNativeDecryption($node);
                if ($nativeDecryptionProvided) {
                    $storedClient = self::storedClientSettings($node);
                    if ($storedClient instanceof stdClass
                        && $storedClient->encryption instanceof stdClass) {
                        $settings['encryption'] = self::objectToArray($storedClient->encryption);
                    }
                }
                self::projectVlessSubscription($settings, $nativeSettings);
            }
            self::projectTransport($settings, $stream);
            self::projectSecurity($settings, $protocol, $stream);
        } elseif ($protocol === 'http') {
            // HTTP has no client transport selector, but its TLS switch is
            // represented by the native stream security value.
            self::projectSecurity($settings, $protocol, $stream);
        } elseif ($protocol === 'hysteria' && $nativeSettings instanceof stdClass) {
            if (property_exists($nativeSettings, 'version') && is_numeric($nativeSettings->version)) {
                $settings['version'] = (int) $nativeSettings->version;
            }
            self::projectHysteriaSecurity($settings, $stream);
        }

        return $settings;
    }

    /**
     * Keep subscription-facing VLESS fields aligned with the native inbound
     * without returning the server-side decryption value.
     */
    private static function projectVlessSubscription(array &$settings, mixed $nativeSettings): void
    {
        $decryption = $nativeSettings instanceof stdClass
            ? self::vlessDecryption($nativeSettings)
            : 'none';
        $encryption = is_array($settings['encryption'] ?? null)
            ? $settings['encryption']
            : [];
        $public = isset($encryption['encryption'])
            ? trim((string) $encryption['encryption'])
            : '';

        // Never let a private server value escape through the request-local
        // protocol settings object used by subscription renderers.
        unset($encryption['decryption']);
        if ($decryption !== 'none') {
            if (($encryption['enabled'] ?? false) !== true || $public === '') {
                self::failAt(
                    'client_settings.encryption.encryption',
                    'VLESS 入站启用服务端加密时必须提供匹配的客户端公开值。',
                );
            }
            $encryption['enabled'] = true;
        } else {
            $encryption['enabled'] = false;
            $encryption['encryption'] = null;
        }
        $settings['encryption'] = $encryption;

        if ($nativeSettings instanceof stdClass && property_exists($nativeSettings, 'flow')) {
            if ($nativeSettings->flow !== null && !is_string($nativeSettings->flow)) {
                self::fail('xray_config.inbounds.0.settings.flow must be a string or null');
            }
            $settings['flow'] = $nativeSettings->flow === null
                ? null
                : trim($nativeSettings->flow);
        }
    }

    /** Read and normalize the native VLESS server-side decryption value. */
    private static function vlessDecryption(?stdClass $nativeSettings): string
    {
        if ($nativeSettings === null || !property_exists($nativeSettings, 'decryption')
            || $nativeSettings->decryption === null || $nativeSettings->decryption === '') {
            return 'none';
        }
        if (!is_string($nativeSettings->decryption)) {
            self::fail('xray_config.inbounds.0.settings.decryption must be a string');
        }
        $value = trim($nativeSettings->decryption);
        return strtolower($value) === 'none' || $value === '' ? 'none' : $value;
    }

    /** Apply the subscription projection to a request-local Server model. */
    public static function applySubscriptionProjection(Server $node): void
    {
        $node->setAttribute('protocol_settings', self::projectedProtocolSettings($node));
    }

    /**
     * Reconcile the native VLESS listener with its public client projection.
     *
     * The native decryption value is the server-side secret and is read only
     * from the effective inbound.  The optional client_settings object can
     * carry the public client encryption value and flow, but can never carry
     * decryption.  The caller invokes this inside the same transaction as the
     * xray_config write so a native decryption change cannot leave a stale
     * subscription profile behind.  The legacy protocol settings remain the
     * baseline and are never overwritten by this reconciliation.
     */
    public static function synchronizeVlessRuntimeSettings(
        Server $node,
        stdClass $effective,
        ?stdClass $clientSettings = null,
        ?stdClass $previousEffective = null,
    ): void {
        if (self::nodeProtocol($node) !== 'vless') {
            if ($clientSettings !== null && get_object_vars($clientSettings) !== []) {
                self::fail('client_settings is only supported for VLESS instances');
            }
            return;
        }

        if ($clientSettings !== null) {
            $clientSettings = self::normalizeClientSettings($clientSettings);
        }

        $inbound = self::effectiveInbound(self::managedInbound($node), $effective);
        $nativeSettings = $inbound->settings instanceof stdClass ? $inbound->settings : null;
        $decryption = self::vlessDecryption($nativeSettings);
        [$nativeDecryptionProvided] = self::explicitNativeDecryption($node);
        $previousInbound = $previousEffective
            ? self::effectiveInbound(self::managedInbound($node), $previousEffective)
            : null;
        $previousDecryption = $previousInbound && $previousInbound->settings instanceof stdClass
            ? self::vlessDecryption($previousInbound->settings)
            : null;

        $nativeFlowProvided = $nativeSettings !== null && property_exists($nativeSettings, 'flow');
        $nativeFlow = null;
        if ($nativeFlowProvided) {
            if ($nativeSettings->flow !== null && !is_string($nativeSettings->flow)) {
                self::fail('xray_config.inbounds.0.settings.flow must be a string or null');
            }
            $nativeFlow = $nativeSettings->flow === null ? null : trim($nativeSettings->flow);
        }

        $clientFlowProvided = $clientSettings !== null && property_exists($clientSettings, 'flow');
        $clientFlow = $clientFlowProvided
            ? ($clientSettings->flow === null ? null : trim((string) $clientSettings->flow))
            : null;
        if ($nativeFlowProvided && $clientFlowProvided
            && ($nativeFlow ?? '') !== ($clientFlow ?? '')) {
            self::failAt('client_settings.flow', '客户端 flow 必须与托管 VLESS 入站一致。');
        }

        // protocol_settings is the legacy baseline.  Native flow and
        // decryption are projected in memory from the effective inbound and
        // must never be written back here: doing so makes a later native
        // override removal inherit the value that was meant to be removed.
        $legacySettings = $node->protocol_settings;
        if (!is_array($legacySettings)) $legacySettings = [];
        $legacyEncryption = is_array($legacySettings['encryption'] ?? null)
            ? $legacySettings['encryption']
            : [];
        $storedEncryption = self::storedClientEncryption($node);
        $clientEncryptionProvided = $clientSettings !== null
            && property_exists($clientSettings, 'encryption');
        $clientPublicProvided = false;
        $clientPublic = null;
        $clientEnabled = $storedEncryption !== []
            ? (bool) ($storedEncryption['enabled'] ?? false)
            : (bool) ($legacyEncryption['enabled'] ?? false);
        if ($clientEncryptionProvided) {
            $clientEncryption = $clientSettings->encryption;
            if ($clientEncryption === null) {
                $clientEnabled = false;
                $clientPublicProvided = true;
            } else {
                $clientEnabled = property_exists($clientEncryption, 'enabled')
                    ? $clientEncryption->enabled
                    : $clientEnabled;
                if (property_exists($clientEncryption, 'encryption')) {
                    $clientPublicProvided = true;
                    $clientPublic = $clientEncryption->encryption === null
                        ? null
                        : trim((string) $clientEncryption->encryption);
                }
            }
        }

        // An explicit native decryption override owns its public counterpart
        // while it is present.  Once that override is removed, the original
        // legacy pair is authoritative again; never reuse the public value
        // belonging to the removed native override.
        $legacyEnabled = (bool) ($legacyEncryption['enabled'] ?? false);
        $legacyPublic = isset($legacyEncryption['encryption'])
            ? trim((string) $legacyEncryption['encryption'])
            : null;
        $public = $clientPublicProvided
            ? $clientPublic
            : ($nativeDecryptionProvided
                ? ($storedEncryption !== []
                    ? ($storedEncryption['encryption'] ?? null)
                    : ($legacyEnabled ? $legacyPublic : null))
                : ($legacyEnabled ? $legacyPublic : null));
        if (!$clientPublicProvided) {
            $clientEnabled = $nativeDecryptionProvided && $storedEncryption !== []
                ? (bool) ($storedEncryption['enabled'] ?? false)
                : $legacyEnabled;
        }

        $decryptionChanged = $previousDecryption !== null
            && $previousDecryption !== $decryption;
        if ($decryption !== 'none') {
            // There is no safe PHP-side derivation of the client public value
            // from the native server value.  When the server value changes,
            // require the UI to submit the new public value atomically.
            if ($nativeDecryptionProvided && $decryptionChanged && !$clientPublicProvided) {
                self::failAt(
                    'client_settings.encryption.encryption',
                    '变更 VLESS 服务端加密时必须同时提供客户端公开值。',
                );
            }
            if (!$clientEnabled || $public === null || $public === '') {
                self::failAt(
                    'client_settings.encryption.encryption',
                    'VLESS 入站启用服务端加密时必须提供客户端公开值。',
                );
            }
            self::validateVlessEncryptionPair($decryption, $public);
            // The public half is required for subscription rendering, but it
            // is not part of the native inbound and must not become a source
            // of server-side decryption defaults.
            $node->xray_client_settings = (object) [
                'encryption' => (object) [
                    'enabled' => true,
                    'encryption' => $public,
                ],
            ];
        } else {
            if ($clientEncryptionProvided && $clientEnabled && $public !== null && $public !== '') {
                self::failAt(
                    'client_settings.encryption.enabled',
                    '未启用 VLESS 服务端加密时不能提交客户端加密公开值。',
                );
            }
            // Retain an explicit disabled marker so a future native enable
            // cannot silently reuse a legacy public value after this native
            // override has been removed.
            $node->xray_client_settings = (object) [
                'encryption' => (object) [
                    'enabled' => false,
                    'encryption' => null,
                ],
            ];
        }
    }

    /**
     * Verify the generated X25519 vlessenc pair when its recognizable profile
     * is used.  Older installations may contain an opaque core-supported
     * value, so those remain accepted and are still checked by Node runtime.
     */
    private static function validateVlessEncryptionPair(string $decryption, string $encryption): void
    {
        $privatePrefix = 'mlkem768x25519plus.native.600s.';
        $publicPrefix = 'mlkem768x25519plus.native.0rtt.';
        $looksLikePair = str_starts_with($decryption, $privatePrefix)
            || str_starts_with($encryption, $publicPrefix);
        if (!$looksLikePair) return;
        if (!str_starts_with($decryption, $privatePrefix)
            || !str_starts_with($encryption, $publicPrefix)) {
            self::failAt(
                'client_settings.encryption.encryption',
                'VLESS 服务端和客户端加密参数必须使用同一配对。',
            );
        }

        $decode = static function (string $value): ?string {
            $raw = substr($value, strrpos($value, '.') + 1);
            if ($raw === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $raw)) return null;
            $decoded = base64_decode(strtr($raw, '-_', '+/') . str_repeat('=', (4 - strlen($raw) % 4) % 4), true);
            return $decoded === false ? null : $decoded;
        };
        $private = $decode($decryption);
        $public = $decode($encryption);
        if ($private === null || $public === null || strlen($private) !== 32
            || strlen($public) !== 32
            || !function_exists('sodium_crypto_scalarmult_base')
            || !hash_equals(sodium_crypto_scalarmult_base($private), $public)) {
            self::failAt(
                'client_settings.encryption.encryption',
                'VLESS 服务端和客户端加密参数必须使用同一配对。',
            );
        }
    }

    private static function projectTransport(array &$settings, mixed $stream): void
    {
        if (!$stream instanceof stdClass) return;
        $network = strtolower(trim((string) ($stream->network ?? 'tcp')));
        if ($network === '') $network = 'tcp';
        if ($network === 'http') $network = 'h2';
        if ($network === 'splithttp') $network = 'xhttp';
        $settings['network'] = $network;

        $nativeKey = self::transportKey($network);
        $transport = $nativeKey && property_exists($stream, $nativeKey)
            ? self::objectToArray($stream->$nativeKey)
            : [];
        if (!is_array($transport)) $transport = [];
        if ($network === 'ws' && isset($transport['host'])
            && (!isset($transport['headers']['Host']) || $transport['headers']['Host'] === '')) {
            $transport['headers'] ??= [];
            $transport['headers']['Host'] = $transport['host'];
            unset($transport['host']);
        }
        $settings['network_settings'] = $transport;
    }

    private static function projectSecurity(array &$settings, string $protocol, mixed $stream): void
    {
        if (!$stream instanceof stdClass) return;
        $security = strtolower(trim((string) ($stream->security ?? '')));
        $tlsMode = match ($security) {
            'reality' => 2,
            'tls' => 1,
            default => 0,
        };

        if ($protocol === 'vless' && array_key_exists('server_tls', $settings)) {
            $settings['server_tls'] = $tlsMode;
        }
        if (in_array($protocol, ['vmess', 'vless', 'trojan', 'http'], true)) {
            $settings['tls'] = $tlsMode;
        }

        $tls = property_exists($stream, 'tlsSettings')
            ? self::objectToArray($stream->tlsSettings)
            : [];
        if (is_array($tls)) {
            $legacyTls = is_array($settings['tls_settings'] ?? null) ? $settings['tls_settings'] : [];
            foreach (['serverName' => 'server_name', 'allowInsecure' => 'allow_insecure'] as $from => $to) {
                if (array_key_exists($from, $tls)) $legacyTls[$to] = $tls[$from];
            }
            $settings['tls_settings'] = $legacyTls;
            if (isset($tls['fingerprint']) && is_string($tls['fingerprint']) && $tls['fingerprint'] !== '') {
                $settings['utls'] = ['enabled' => true, 'fingerprint' => $tls['fingerprint']];
            }
        }

        if ($tlsMode === 2 && property_exists($stream, 'realitySettings')) {
            $reality = self::objectToArray($stream->realitySettings);
            if (is_array($reality)) {
                $legacyReality = is_array($settings['reality_settings'] ?? null)
                    ? $settings['reality_settings']
                    : [];
                if (isset($reality['publicKey']) && is_string($reality['publicKey'])) {
                    $legacyReality['public_key'] = $reality['publicKey'];
                }
                if (isset($reality['shortIds']) && is_array($reality['shortIds']) && $reality['shortIds'] !== []) {
                    $legacyReality['short_id'] = (string) $reality['shortIds'][0];
                }
                if (isset($reality['serverNames']) && is_array($reality['serverNames']) && $reality['serverNames'] !== []) {
                    $legacyReality['server_name'] = (string) $reality['serverNames'][0];
                }
                if (isset($reality['fingerprint']) && is_string($reality['fingerprint']) && $reality['fingerprint'] !== '') {
                    $settings['utls'] = ['enabled' => true, 'fingerprint' => $reality['fingerprint']];
                }
                // privateKey is deliberately never copied to subscription
                // settings; it is a Node-only server secret.
                $settings['reality_settings'] = $legacyReality;
            }
        }
    }

    private static function projectHysteriaSecurity(array &$settings, mixed $stream): void
    {
        if (!$stream instanceof stdClass) return;
        if (strtolower(trim((string) ($stream->security ?? ''))) !== 'tls') return;
        $tls = property_exists($stream, 'tlsSettings') ? self::objectToArray($stream->tlsSettings) : [];
        if (!is_array($tls)) return;
        $legacy = is_array($settings['tls'] ?? null) ? $settings['tls'] : [];
        foreach (['serverName' => 'server_name', 'allowInsecure' => 'allow_insecure'] as $from => $to) {
            if (array_key_exists($from, $tls)) $legacy[$to] = $tls[$from];
        }
        $settings['tls'] = $legacy;
    }

    private static function inboundStreamSettings(Server $node): stdClass
    {
        $settings = $node->protocol_settings ?? [];
        $protocol = self::nodeProtocol($node);
        $serverTls = data_get($settings, 'server_tls');
        $tlsMode = (int) ($serverTls === null ? data_get($settings, 'tls', 0) : $serverTls);

        // These builders intentionally retain only the generated reusePort
        // socket option.  Adding a synthetic TCP network here would make the
        // baseline differ from the actual NodeConfig for SOCKS/HTTP/SS.
        if ($protocol === 'shadowsocks' || $protocol === 'socks'
            || ($protocol === 'http' && $tlsMode === 0)) {
            return (object) ['sockopt' => (object) ['reusePort' => true]];
        }

        // Hysteria replaces the generic stream settings entirely.  Its
        // certificate is loaded by Node from cert_config, so only safe public
        // transport fields belong in this projection.
        if ($protocol === 'hysteria') {
            $version = (int) data_get($settings, 'version', 2);
            $stream = (object) [
                'network' => 'hysteria',
                'hysteriaSettings' => (object) [
                    'version' => $version,
                    'udpIdleTimeout' => 60,
                ],
            ];
            $up = (int) data_get($settings, 'bandwidth.up', 0);
            $down = (int) data_get($settings, 'bandwidth.down', 0);
            if ($up > 0 || $down > 0) {
                $quic = new stdClass();
                if ($up > 0) $quic->brutalUp = $up . ' mbps';
                if ($down > 0) $quic->brutalDown = $down . ' mbps';
                $stream->finalMask = (object) ['quicParams' => $quic];
            }
            if (self::nodeHasCertificate($node)) {
                $stream->security = 'tls';
                $tls = data_get($settings, 'tls');
                $tls = is_array($tls) || $tls instanceof stdClass
                    ? self::object($tls, 'tls')
                    : new stdClass();
                $tlsSettings = self::mapTlsSettings($tls);
                $tlsSettings->alpn = ['h3'];
                $stream->tlsSettings = $tlsSettings;
            }
            return $stream;
        }

        $stream = new stdClass();
        $network = (string) (data_get($settings, 'network') ?: 'tcp');
        if ($network !== '') {
            $normalizedNetwork = strtolower($network);
            if (in_array($normalizedNetwork, ['http', 'h2'], true)) $normalizedNetwork = 'h2';
            if (in_array($normalizedNetwork, ['splithttp'], true)) $normalizedNetwork = 'xhttp';
            $stream->network = $normalizedNetwork;
            $networkSettings = data_get($settings, 'network_settings');
            $nativeKey = match ($normalizedNetwork) {
                'ws' => 'wsSettings', 'grpc' => 'grpcSettings', 'httpupgrade' => 'httpupgradeSettings',
                'h2' => 'httpSettings', 'xhttp' => 'xhttpSettings', default => null,
            };
            if ($nativeKey && ($networkSettings instanceof stdClass
                || (is_array($networkSettings) && $networkSettings !== [] && !array_is_list($networkSettings)))) {
                $raw = self::object($networkSettings, 'network_settings');
                $mapped = new stdClass();
                if ($nativeKey === 'wsSettings' || $nativeKey === 'httpupgradeSettings' || $nativeKey === 'xhttpSettings') {
                    foreach (['path', 'host', 'mode', 'extra'] as $key) {
                        if (property_exists($raw, $key)) $mapped->$key = self::cloneValue($raw->$key);
                    }
                    if ($nativeKey === 'wsSettings' && property_exists($raw, 'headers')) $mapped->headers = self::cloneValue($raw->headers);
                } elseif ($nativeKey === 'grpcSettings') {
                    if (property_exists($raw, 'serviceName')) $mapped->serviceName = self::cloneValue($raw->serviceName);
                    if (property_exists($raw, 'service_name')) $mapped->serviceName = self::cloneValue($raw->service_name);
                } elseif ($nativeKey === 'httpSettings') {
                    if (property_exists($raw, 'path')) $mapped->path = self::cloneValue($raw->path);
                    if (property_exists($raw, 'host')) $mapped->host = is_array($raw->host) ? $raw->host : [$raw->host];
                }
                $stream->$nativeKey = $mapped;
            }
        }

        $serverTls = data_get($settings, 'server_tls');
        $tlsMode = (int) ($serverTls === null ? data_get($settings, 'tls', 0) : $serverTls);
        $reality = data_get($settings, 'reality_settings');
        if ($tlsMode === 2 || is_array($reality) || $reality instanceof stdClass) {
            $stream->security = 'reality';
            if (is_array($reality) || $reality instanceof stdClass) {
                $stream->realitySettings = self::mapRealitySettings(self::object($reality, 'reality_settings'));
            }
        } elseif ($tlsMode > 0 || (($node->cert_config['cert_mode'] ?? null) && $node->cert_config['cert_mode'] !== 'none')) {
            $stream->security = 'tls';
            $tls = data_get($settings, 'tls_settings');
            if (is_array($tls) || $tls instanceof stdClass) {
                $stream->tlsSettings = self::mapTlsSettings(self::object($tls, 'tls_settings'));
            }
        }
        return $stream;
    }

    private static function nodeHasCertificate(Server $node): bool
    {
        $certificate = $node->cert_config;
        if (!is_array($certificate)) return false;
        $mode = strtolower(trim((string) ($certificate['cert_mode'] ?? $certificate['mode'] ?? '')));
        if ($mode === '') {
            $hasContent = self::nonEmptyString($certificate['cert_content'] ?? null) !== null
                || self::nonEmptyString($certificate['key_content'] ?? null) !== null;
            $hasFile = self::nonEmptyString($certificate['cert_file'] ?? null) !== null
                || self::nonEmptyString($certificate['key_file'] ?? null) !== null;
            $mode = $hasContent ? 'content' : ($hasFile ? 'file' : ((bool) ($certificate['auto_tls'] ?? false) ? 'http' : 'none'));
        }
        return $mode !== '' && $mode !== 'none';
    }

    /**
     * Validate and prepare the persistence columns for a candidate.  Server
     * sourced candidates are generated from the source node plus the
     * dedicated credential and retain a non-secret resolution snapshot.
     */
    public static function prepareCandidate(array $input, ?Outbound $existing = null): array
    {
        $sourceType = self::normalizeSourceType($input['source_type'] ?? $existing?->source_type);
        $resolution = self::normalizeResolutionMode($input['resolution_mode'] ?? $existing?->resolution_mode);

        if ($sourceType === Outbound::SOURCE_MANUAL) {
            if (array_key_exists('config_patch', $input)) {
                self::fail('config_patch is only supported for a server-sourced outbound');
            }
            $configValue = array_key_exists('config', $input) ? $input['config'] : $existing?->config;
            $config = self::object($configValue, 'config');
            if ($existing?->config instanceof stdClass) {
                $previousProtocol = strtolower(trim((string) ($existing->config->protocol ?? '')));
                $nextProtocol = strtolower(trim((string) ($config->protocol ?? '')));
                if ($previousProtocol !== '' && $nextProtocol !== '' && $previousProtocol !== $nextProtocol
                    && self::containsSecretMask($config)) {
                    self::fail('Changing the outbound protocol requires new credential values');
                }
                if ($previousProtocol === $nextProtocol) {
                    $config = self::preserveMaskedSecrets($config, $existing->config);
                }
            }
            self::validateOutbound($config);
            return [
                'config' => $config,
                'source_type' => Outbound::SOURCE_MANUAL,
                'source_node_id' => null,
                'resolution_mode' => Outbound::RESOLUTION_PINNED,
                'service_credential' => null,
                'source_snapshot' => null,
                'config_override' => null,
            ];
        }

        $sourceId = $input['source_node_id'] ?? $existing?->source_node_id;
        if (filter_var($sourceId, FILTER_VALIDATE_INT) === false || (int) $sourceId < 1) {
            self::fail('source_node_id is required for a server-sourced outbound');
        }
        $source = Server::query()->find((int) $sourceId);
        if (!$source) {
            self::fail('The selected source instance is unavailable');
        }
        if (!self::supports($source)) {
            self::fail('The selected source instance cannot provide an Xray outbound');
        }
        if (array_key_exists('service_credential', $input)) {
            $credential = $input['service_credential'];
        } else {
            $credential = $existing?->service_credential;
        }
        if (!is_array($credential) || $credential === []) {
            self::fail('A dedicated service credential is required for a server-sourced outbound');
        }

        $base = self::buildSourceOutbound($source, $credential);
        $override = self::sourceOverrideForCandidate($existing, $base, $source);
        if ($existing && (int) $existing->source_node_id !== (int) $source->id) {
            $override = new stdClass();
        }
        if (array_key_exists('config', $input) && array_key_exists('config_patch', $input)) {
            self::fail('Use either config or config_patch for a server-sourced outbound');
        }
        if (array_key_exists('config_patch', $input)) {
            $patch = self::validateSourceOverride($input['config_patch']);
            $override = self::mergeSourceOverridePatch($override, $patch);
        } elseif (array_key_exists('config', $input) && $input['config'] !== null) {
            // A full config remains accepted for compatibility.  Its
            // generated endpoint/account fields are ignored; the sparse
            // difference of writable native fields becomes the override.
            $fullConfig = self::object($input['config'], 'config');
            self::validateOutbound($fullConfig);
            $override = self::extractSourceOverride($fullConfig, $base);
        }
        $config = self::applySourceOverride($base, $override);
        self::validateOutbound($config);

        return [
            'config' => $config,
            'source_type' => Outbound::SOURCE_NODE,
            'source_node_id' => (int) $source->id,
            'resolution_mode' => $resolution,
            'service_credential' => $credential,
            'source_snapshot' => self::makeSourceSnapshot($source, $credential, $config),
            'config_override' => self::sourceOverrideIsEmpty($override) ? null : $override,
        ];
    }

    /**
     * Build the same unsaved candidate that saveOutbound persists.  Keeping
     * this in the service makes the read-only outbound preflight and save
     * share credential masking, source patch and protocol validation rules.
     */
    public static function candidateFromInput(array $input, ?Outbound $existing = null): Outbound
    {
        $prepared = self::prepareCandidate($input, $existing);
        $candidate = $existing ? clone $existing : new Outbound();
        $candidate->fill([
            'name' => $input['name'] ?? ($existing?->name ?? ''),
            'enabled' => array_key_exists('enabled', $input)
                ? (bool) $input['enabled']
                : (bool) ($existing?->enabled ?? true),
            ...$prepared,
        ]);
        return $candidate;
    }

    /**
     * Validate an unsaved outbound candidate against all nodes currently
     * bound to it.  The candidate is supplied as an override to effective(),
     * so live source edits are tested without freezing or writing a snapshot.
     *
     * @param iterable<Server> $boundNodes
     * @return array<int, array{node_id:int, config_revision:int, config_hash:string}>
     */
    public static function preflightOutbound(Outbound $candidate, iterable $boundNodes): array
    {
        $candidateId = (int) $candidate->getKey();
        $overrides = $candidateId > 0 ? [$candidateId => $candidate] : [];
        self::assertCandidateGraph($candidate, [], $overrides);
        $resolved = self::resolveCandidate($candidate, null, $overrides);
        self::validateOutbound($resolved);

        $affected = [];
        foreach ($boundNodes as $node) {
            $effective = self::effective($node, $overrides);
            $affected[] = [
                'node_id' => (int) $node->id,
                'config_revision' => (int) ($node->config_revision ?? 0),
                'config_hash' => self::hash($effective),
            ];
        }
        return $affected;
    }

    /** Candidate response without encrypted credentials or generated secrets. */
    public static function candidateSnapshot(Outbound $candidate, ?Server $target = null): array
    {
        $resolved = $candidate->config instanceof stdClass ? self::cloneValue($candidate->config) : new stdClass();
        $sourceSnapshot = $candidate->source_snapshot instanceof stdClass
            ? self::cloneValue($candidate->source_snapshot)
            : null;
        $override = null;
        if ($candidate->source_type === Outbound::SOURCE_NODE) {
            $source = $candidate->sourceNode ?: Server::query()->find($candidate->source_node_id);
            if (!$source) {
                self::fail('The outbound source instance is unavailable');
            }
            self::assertCandidateGraph($candidate);
            $base = self::buildSourceOutbound($source, $candidate->service_credential ?: []);
            $override = self::sourceOverrideForCandidate($candidate, $base, $source);
            if ($candidate->resolution_mode === Outbound::RESOLUTION_LIVE) {
                $resolved = self::applySourceOverride($base, $override);
                $sourceSnapshot = self::makeSourceSnapshot(
                    $source,
                    $candidate->service_credential,
                    $resolved,
                );
            }
        }
        return [
            'id' => (int) $candidate->id,
            'name' => $candidate->name,
            'enabled' => (bool) $candidate->enabled,
            'source_type' => $candidate->source_type ?: Outbound::SOURCE_MANUAL,
            'source_node_id' => $candidate->source_node_id ? (int) $candidate->source_node_id : null,
            'resolution_mode' => $candidate->resolution_mode ?: Outbound::RESOLUTION_PINNED,
            'credential_configured' => is_array($candidate->service_credential)
                && $candidate->service_credential !== [],
            'source_node' => $candidate->relationLoaded('sourceNode') && $candidate->sourceNode
                ? [
                    'id' => (int) $candidate->sourceNode->id,
                    'name' => $candidate->sourceNode->name,
                    'type' => $candidate->sourceNode->type,
                ]
                : null,
            'config' => self::redactSecrets($resolved),
            'config_override' => $override ? self::redactSecrets($override) : null,
            'config_redacted' => true,
            'secret_mask' => '***',
            'config_hash' => self::hash($resolved),
            'source_snapshot' => $sourceSnapshot,
            'updated_at' => $candidate->updated_at instanceof \DateTimeInterface
                ? $candidate->updated_at->format(DATE_ATOM)
                : ($candidate->updated_at !== null ? date(DATE_ATOM, (int) $candidate->updated_at) : null),
        ];
    }

    /** Return a source preview without saving credentials or a candidate. */
    public static function sourcePreview(
        Server $source,
        array $credential,
        ?stdClass $override = null,
        string $mode = Outbound::RESOLUTION_PINNED,
        bool $fullConfig = false,
    ): array
    {
        $mode = self::normalizeResolutionMode($mode);
        $base = self::buildSourceOutbound($source, $credential);
        $configOverride = new stdClass();
        if ($override) {
            if ($fullConfig) {
                self::validateOutbound($override);
                $configOverride = self::extractSourceOverride($override, $base);
            } else {
                $configOverride = self::validateSourceOverride($override);
            }
        }
        $config = self::applySourceOverride($base, $configOverride);
        self::validateOutbound($config);
        return [
            'source_type' => Outbound::SOURCE_NODE,
            'source_node_id' => (int) $source->id,
            'resolution_mode' => $mode,
            'credential_configured' => true,
            'config' => self::redactSecrets($config),
            'config_override' => self::redactSecrets($configOverride),
            'config_redacted' => true,
            'secret_mask' => '***',
            'config_hash' => self::hash($config),
            'source_snapshot' => self::makeSourceSnapshot($source, $credential, $config),
        ];
    }

    private static function validateManagedInbound(stdClass $inbound, ?Server $node = null): void
    {
        foreach (get_object_vars($inbound) as $field => $value) {
            if (!in_array($field, self::MANAGED_INBOUND_FIELDS, true)
                && !in_array($field, ['protocol', 'tag'], true)) {
                self::fail("xray_config.inbounds.0.{$field} is controlled by XBoard");
            }
            if ($field === 'protocol') {
                if (!$node || !is_string($value) || strtolower(trim($value)) !== self::nodeProtocol($node)) {
                    self::fail('xray_config.inbounds.0.protocol is controlled by XBoard');
                }
                continue;
            }
            if ($field === 'tag') {
                if (!$node || !is_string($value) || trim($value) !== self::nodeProtocol($node) . '-in') {
                    self::fail('xray_config.inbounds.0.tag is controlled by XBoard');
                }
                continue;
            }
            if ($value === null) {
                if (in_array($field, ['settings', 'streamSettings', 'sniffing', 'listen', 'port'], true)) {
                    self::fail("xray_config.inbounds.0.{$field} cannot be cleared; submit an object or value");
                }
                continue;
            }
            if (in_array($field, ['settings', 'streamSettings', 'sniffing'], true)
                && !$value instanceof stdClass) {
                self::fail("xray_config.inbounds.0.{$field} must be a JSON object");
            }
        }
        if (($inbound->settings ?? null) instanceof stdClass) {
            foreach (['clients', 'accounts', 'auth', 'password'] as $field) {
                if (property_exists($inbound->settings, $field)) {
                    self::fail("xray_config.inbounds.0.settings.{$field} is managed by XBoard");
                }
            }
            // The VLESS decryption leaf is an intentional disable tombstone.
            // Keep the remaining deny-list narrow: optional native leaves
            // such as sockopt.mark may be removed with null, while the
            // managed flow value and transport/security structures cannot be
            // cleared as a whole.
            self::rejectManagedNulls(
                $inbound->settings,
                'xray_config.inbounds.0.settings',
                [
                    'xray_config.inbounds.0.settings.flow',
                ],
            );
        }
        if (($inbound->streamSettings ?? null) instanceof stdClass) {
            self::rejectManagedNulls(
                $inbound->streamSettings,
                'xray_config.inbounds.0.streamSettings',
                [
                    'xray_config.inbounds.0.streamSettings.network',
                    'xray_config.inbounds.0.streamSettings.security',
                    'xray_config.inbounds.0.streamSettings.tlsSettings',
                    'xray_config.inbounds.0.streamSettings.realitySettings',
                ],
            );
        }
        if (($inbound->sniffing ?? null) instanceof stdClass) {
            self::rejectManagedNulls($inbound->sniffing, 'xray_config.inbounds.0.sniffing');
        }
    }

    /** Reject null tombstones that would remove a managed object subtree. */
    private static function rejectManagedNulls(
        mixed $value,
        string $path,
        array $deniedPaths = [],
    ): void {
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $itemPath = "{$path}.{$key}";
                if ($item === null && in_array($itemPath, $deniedPaths, true)) {
                    if ($itemPath === 'xray_config.inbounds.0.settings.flow') {
                        self::fail('xray_config.inbounds.0.settings.flow cannot be cleared; use an empty string to disable it');
                    }
                    self::fail("{$itemPath} cannot be cleared; provide a replacement structure");
                }
                if ($item === null) {
                    continue;
                }
                if ($item instanceof stdClass || is_array($item)) {
                    self::rejectManagedNulls($item, $itemPath, $deniedPaths);
                }
            }
            return;
        }
        if (is_array($value)) {
            foreach ($value as $index => $item) {
                $itemPath = "{$path}.{$index}";
                if ($item === null) {
                    continue;
                }
                if ($item instanceof stdClass || is_array($item)) {
                    self::rejectManagedNulls($item, $itemPath, $deniedPaths);
                }
            }
        }
    }

    /** Validate an independent native inbound after the managed listener. */
    private static function validateIndependentInbound(mixed $inbound, int $index, array &$seenTags): void
    {
        if (!$inbound instanceof stdClass) {
            self::fail("xray_config.inbounds.{$index} must be an object");
        }
        foreach (get_object_vars($inbound) as $field => $value) {
            if (!in_array($field, self::INBOUND_FIELDS, true)) {
                self::fail("xray_config.inbounds.{$index}.{$field} is not a supported native inbound field");
            }
            if (in_array($field, ['settings', 'streamSettings', 'sniffing'], true)
                && $value !== null && !$value instanceof stdClass) {
                self::fail("xray_config.inbounds.{$index}.{$field} must be a JSON object");
            }
        }
        $protocol = strtolower(trim((string) ($inbound->protocol ?? '')));
        if ($protocol === '' || !in_array($protocol, self::INBOUND_PROTOCOLS, true)) {
            self::fail("xray_config.inbounds.{$index}.protocol is not supported by the Xray control plane");
        }
        $tag = trim((string) ($inbound->tag ?? ''));
        if ($tag === '') {
            self::fail("xray_config.inbounds.{$index}.tag must be a non-empty string");
        }
        $tagKey = strtolower($tag);
        if (in_array($tagKey, ['api', 'api-in'], true)) {
            self::fail("xray_config.inbounds.{$index}.tag '{$tag}' is reserved");
        }
        if (isset($seenTags[$tagKey])) {
            self::failAt("xray_config.inbounds.{$index}.tag", "入站标记 '{$tag}' 已重复。");
        }
        $seenTags[$tagKey] = true;

        if (!property_exists($inbound, 'listen') || $inbound->listen === null
            || !is_string($inbound->listen) || trim($inbound->listen) === '') {
            self::failAt(
                "xray_config.inbounds.{$index}.listen",
                '独立入站必须填写监听地址。',
            );
        }
        if (!property_exists($inbound, 'port') || $inbound->port === null) {
            self::failAt(
                "xray_config.inbounds.{$index}.port",
                '独立入站必须填写监听端口。',
            );
        }
        self::validatePort($inbound->port, "xray_config.inbounds.{$index}.port");
    }

    /** The managed endpoint is generated by Server and must remain valid. */
    private static function validateManagedEndpoint(stdClass $inbound): void
    {
        if (!property_exists($inbound, 'listen') || $inbound->listen === null
            || !is_string($inbound->listen) || trim($inbound->listen) === '') {
            self::failAt('xray_config.inbounds.0.listen', '托管入站必须有监听地址。');
        }
        if (!property_exists($inbound, 'port') || $inbound->port === null) {
            self::failAt('xray_config.inbounds.0.port', '托管入站必须有监听端口。');
        }
        self::validatePort($inbound->port, 'xray_config.inbounds.0.port');
    }

    private static function validatePort(mixed $value, string $path): void
    {
        $valid = is_int($value)
            || (is_string($value) && ctype_digit(trim($value)));
        if (!$valid || (int) $value < 1 || (int) $value > 65535) {
            self::failAt($path, '端口必须是 1 到 65535 之间的整数。');
        }
    }

    private static function validatePolicy(stdClass $policy): void
    {
        foreach (['levels', 'system'] as $field) {
            if (property_exists($policy, $field) && !$policy->$field instanceof stdClass) {
                self::fail("xray_config.policy.{$field} must be a JSON object");
            }
        }
        $level = $policy->levels->{'0'} ?? null;
        if ($level !== null) {
            if (!$level instanceof stdClass) {
                self::fail('xray_config.policy.levels.0 must be a JSON object');
            }
            foreach (['statsUserUplink', 'statsUserDownlink'] as $field) {
                if (property_exists($level, $field) && $level->$field !== true) {
                    self::fail("xray_config.policy.levels.0.{$field} must remain enabled");
                }
            }
        }
    }

    private static function validateReverse(stdClass $reverse, array &$knownTags): void
    {
        if (property_exists($reverse, 'portals')) {
            if (!is_array($reverse->portals)) {
                self::fail('xray_config.reverse.portals must be an array');
            }
            foreach ($reverse->portals as $portal) {
                if (!$portal instanceof stdClass || !is_string($portal->tag ?? null) || trim($portal->tag) === '') {
                    self::fail('xray_config.reverse.portals entries require a tag');
                }
                $knownTags[] = $portal->tag;
            }
        }
    }

    private static function validateRouting(stdClass $routing, array $knownTags): void
    {
        foreach (['balancers', 'rules'] as $field) {
            if (property_exists($routing, $field) && !is_array($routing->$field)) {
                self::fail("xray_config.routing.{$field} must be an array");
            }
        }
        $balancers = [];
        foreach ($routing->balancers ?? [] as $balancer) {
            if (!$balancer instanceof stdClass || !is_string($balancer->tag ?? null) || trim($balancer->tag) === '') {
                self::fail('xray_config.routing.balancers entries require unique tags');
            }
            $tag = $balancer->tag;
            if (isset($balancers[$tag])) {
                self::fail("xray_config.routing.balancers contains duplicate tag '{$tag}'");
            }
            $balancers[$tag] = true;
            if (property_exists($balancer, 'fallbackTag')
                && $balancer->fallbackTag !== null
                && !is_string($balancer->fallbackTag)) {
                self::fail('xray_config.routing balancer fallbackTag must be a string');
            }
            if (property_exists($balancer, 'fallbackTag')
                && $balancer->fallbackTag !== null
                && !in_array($balancer->fallbackTag, $knownTags, true)) {
                self::fail("xray_config.routing balancer fallback '{$balancer->fallbackTag}' does not exist");
            }
        }
        foreach ($routing->rules ?? [] as $rule) {
            if (!$rule instanceof stdClass) {
                self::fail('xray_config.routing.rules entries must be objects');
            }
            if (property_exists($rule, 'enabled') && !is_bool($rule->enabled)) {
                self::fail('xray_config.routing rule enabled must be boolean');
            }
            if (property_exists($rule, 'outboundTag') && property_exists($rule, 'balancerTag')) {
                self::fail('A routing rule cannot target both an outbound and a balancer');
            }
            if (isset($rule->outboundTag) && !is_string($rule->outboundTag)) {
                self::fail('xray_config.routing rule outboundTag must be a string');
            }
            if (isset($rule->balancerTag) && !is_string($rule->balancerTag)) {
                self::fail('xray_config.routing rule balancerTag must be a string');
            }
            if (isset($rule->outboundTag) && !in_array($rule->outboundTag, $knownTags, true)) {
                self::fail("xray_config.routing rule target '{$rule->outboundTag}' does not exist");
            }
            if (isset($rule->balancerTag) && !isset($balancers[$rule->balancerTag])) {
                self::fail("xray_config.routing rule balancer '{$rule->balancerTag}' does not exist");
            }
        }
    }

    private static function appendSystemOutbounds(stdClass $config): void
    {
        if (!property_exists($config, 'outbounds')) {
            $config->outbounds = [];
        }
        $tags = [];
        foreach ($config->outbounds as $outbound) {
            if ($outbound instanceof stdClass && is_string($outbound->tag ?? null)) {
                $tags[strtolower($outbound->tag)] = true;
            }
        }
        if (!isset($tags['direct'])) {
            $config->outbounds[] = (object) ['tag' => 'direct', 'protocol' => 'freedom'];
        }
        if (!isset($tags['block'])) {
            $config->outbounds[] = (object) ['tag' => 'block', 'protocol' => 'blackhole'];
        }
    }

    private static function selectDefaultOutbound(stdClass $config, Server $node): void
    {
        $selected = trim((string) ($node->default_outbound_tag ?: 'direct'));
        foreach ($config->outbounds as $index => $outbound) {
            if ($outbound instanceof stdClass && ($outbound->tag ?? null) === $selected) {
                if ($index > 0) {
                    array_splice($config->outbounds, $index, 1);
                    array_unshift($config->outbounds, $outbound);
                }
                return;
            }
        }
        self::failAt('default_outbound_tag', "默认出站 '{$selected}' 不存在。", null);
    }

    private static function isPanelApiRule(stdClass $rule): bool
    {
        return ($rule->outboundTag ?? null) === 'api'
            && is_array($rule->inboundTag ?? null)
            && in_array('api', $rule->inboundTag, true);
    }

    /**
     * Xray rejects field rules without a matcher. Older panels used such a
     * row as a catch-all, but the selected first outbound already owns that
     * fallback behavior.
     */
    private static function isMatchlessFieldRule(stdClass $rule): bool
    {
        if (strtolower(trim((string) ($rule->type ?? 'field'))) !== 'field') {
            return false;
        }

        foreach (get_object_vars($rule) as $field => $value) {
            if (in_array($field, ['type', 'outboundTag', 'balancerTag', 'enabled', 'ruleTag', 'webhook'], true)) {
                continue;
            }
            if (is_string($value) && trim($value) !== '') return false;
            if (is_array($value) && $value !== []) return false;
            if ($value instanceof stdClass && get_object_vars($value) !== []) return false;
            if ($value !== null && !is_string($value) && !is_array($value) && !$value instanceof stdClass) return false;
        }
        return true;
    }

    /** Keep the panel-owned API row canonical even when legacy/native input edits it. */
    private static function maintainPanelApiRule(stdClass $config): void
    {
        if (!property_exists($config, 'routing')) {
            $config->routing = new stdClass();
        }
        if (!$config->routing instanceof stdClass) {
            return;
        }
        if (!property_exists($config->routing, 'rules')) {
            $config->routing->rules = [];
        }
        if (!is_array($config->routing->rules)) {
            return;
        }

        $rules = array_values(array_filter(
            $config->routing->rules,
            static fn ($rule) => !$rule instanceof stdClass || !self::isPanelApiRule($rule),
        ));
        array_unshift($rules, self::object([
            'type' => 'field',
            'inboundTag' => ['api'],
            'outboundTag' => 'api',
            'enabled' => true,
        ]));
        $config->routing->rules = $rules;
    }

    /** Remove legacy catch-all rows after reference validation has run. */
    private static function removeMatchlessFieldRules(stdClass $config): void
    {
        if (!isset($config->routing) || !$config->routing instanceof stdClass
            || !is_array($config->routing->rules ?? null)) {
            return;
        }

        $config->routing->rules = array_values(array_filter(
            $config->routing->rules,
            static fn ($rule) => !$rule instanceof stdClass || !self::isMatchlessFieldRule($rule),
        ));
    }

    private static function assertCandidateGraph(
        Outbound $candidate,
        array $stack = [],
        array $candidateOverrides = [],
    ): void
    {
        if (isset($stack[$candidate->id])) {
            self::fail('Outbound source references contain a cycle');
        }
        $stack[$candidate->id] = true;
        if ($candidate->source_type !== Outbound::SOURCE_NODE || !$candidate->source_node_id) {
            return;
        }
        $source = $candidate->sourceNode ?: Server::query()->find($candidate->source_node_id);
        if (!$source) {
            self::fail('The outbound source instance is unavailable');
        }
        if (!self::supports($source)) {
            self::fail('The outbound source instance cannot provide an Xray outbound');
        }
        foreach (self::normalizeBindings($source->outbound_bindings ?? []) as $binding) {
            $next = self::candidateById($binding['outbound_id'], $candidateOverrides);
            if ($next && ($binding['enabled'] ?? true)) {
                self::assertCandidateGraph($next, $stack, $candidateOverrides);
            }
        }
    }

    private static function resolveCandidate(
        Outbound $candidate,
        ?Server $target,
        array $candidateOverrides = [],
    ): stdClass
    {
        if ($candidate->source_type !== Outbound::SOURCE_NODE) {
            return self::cloneValue($candidate->config instanceof stdClass ? $candidate->config : new stdClass());
        }
        $source = $candidate->sourceNode ?: Server::query()->find($candidate->source_node_id);
        if (!$source) {
            self::fail('The outbound source instance is unavailable');
        }
        if ($target && (int) $target->id === (int) $source->id) {
            self::fail('An outbound source cannot point back to its target instance');
        }
        if ($candidate->resolution_mode !== Outbound::RESOLUTION_LIVE) {
            return self::cloneValue($candidate->config instanceof stdClass ? $candidate->config : new stdClass());
        }
        $credential = $candidate->service_credential;
        if (!is_array($credential) || $credential === []) {
            self::fail('The live outbound source has no dedicated credential');
        }
        $base = self::buildSourceOutbound($source, $credential);
        return self::applySourceOverride(
            $base,
            self::sourceOverrideForCandidate($candidate, $base, $source),
        );
    }

    private static function candidateById(int $id, array $candidateOverrides = []): ?Outbound
    {
        if (array_key_exists($id, $candidateOverrides)) {
            return $candidateOverrides[$id] instanceof Outbound ? $candidateOverrides[$id] : null;
        }
        return Outbound::query()->find($id);
    }

    private static function buildSourceOutbound(Server $source, array $credential): stdClass
    {
        $protocol = self::nodeProtocol($source);
        $sourceSettings = self::projectedProtocolSettings($source);
        $host = trim((string) $source->host);
        if ($host === '') {
            self::fail('The source instance has no endpoint host');
        }
        $port = self::sourcePort($source);
        $settings = new stdClass();

        switch ($protocol) {
            case 'vless':
                $uuid = self::credentialString($credential, ['uuid', 'id']);
                if ($uuid === '') self::fail('The VLESS service credential requires uuid');
                if (!Str::isUuid($uuid)) self::fail('The VLESS service credential uuid is invalid');
                $user = (object) [
                    'id' => $uuid,
                    'encryption' => self::credentialString($credential, ['encryption'], 'none'),
                ];
                $flow = self::credentialString($credential, ['flow'], (string) data_get($sourceSettings, 'flow', ''));
                if ($flow !== '') $user->flow = $flow;
                $settings->vnext = [(object) ['address' => $host, 'port' => $port, 'users' => [$user]]];
                $outbound = (object) ['tag' => self::sourceTag($source), 'protocol' => 'vless', 'settings' => $settings];
                break;
            case 'vmess':
                $uuid = self::credentialString($credential, ['uuid', 'id']);
                if ($uuid === '') self::fail('The VMess service credential requires uuid');
                if (!Str::isUuid($uuid)) self::fail('The VMess service credential uuid is invalid');
                $user = (object) [
                    'id' => $uuid,
                    'security' => self::credentialString($credential, ['security'], 'auto'),
                ];
                $settings->vnext = [(object) ['address' => $host, 'port' => $port, 'users' => [$user]]];
                $outbound = (object) ['tag' => self::sourceTag($source), 'protocol' => 'vmess', 'settings' => $settings];
                break;
            case 'trojan':
                $password = self::credentialString($credential, ['password', 'pass']);
                if ($password === '') self::fail('The Trojan service credential requires password');
                $server = (object) ['address' => $host, 'port' => $port, 'password' => $password];
                $flow = self::credentialString($credential, ['flow'], '');
                if ($flow !== '') $server->flow = $flow;
                $settings->servers = [$server];
                $outbound = (object) ['tag' => self::sourceTag($source), 'protocol' => 'trojan', 'settings' => $settings];
                break;
            case 'shadowsocks':
                $password = self::credentialString($credential, ['password', 'pass']);
                $cipher = self::credentialString($credential, ['cipher', 'method'], (string) data_get($sourceSettings, 'cipher', ''));
                if ($password === '' || $cipher === '') self::fail('The Shadowsocks service credential requires method and password');
                $settings->servers = [(object) ['address' => $host, 'port' => $port, 'method' => $cipher, 'password' => $password]];
                $outbound = (object) ['tag' => self::sourceTag($source), 'protocol' => 'shadowsocks', 'settings' => $settings];
                break;
            case 'socks':
            case 'http':
                $server = (object) ['address' => $host, 'port' => $port];
                $username = self::credentialString($credential, ['username', 'user']);
                $password = self::credentialString($credential, ['password', 'pass']);
                if ($protocol === 'socks') {
                    if ($username !== '') $server->users = [(object) ['user' => $username, 'pass' => $password]];
                    $settings->servers = [$server];
                } else {
                    if ($username !== '') $server->users = [(object) ['user' => $username, 'pass' => $password]];
                    $settings->servers = [$server];
                }
                $outbound = (object) ['tag' => self::sourceTag($source), 'protocol' => $protocol, 'settings' => $settings];
                break;
            case 'hysteria':
                // The linked Xray core accepts only version/address/port for
                // Hysteria clients and has no field for the server user auth.
                // Refuse the source candidate instead of silently dropping it.
                self::fail('Hysteria source outbounds are unavailable until the node core supports service credentials');
            default:
                self::fail('The source instance protocol cannot provide a native Xray outbound');
        }

        $streamSettings = self::sourceStreamSettings($source);
        if ($streamSettings) $outbound->streamSettings = $streamSettings;
        return $outbound;
    }

    /**
     * Merge a sparse source patch into the persisted sparse override.  Null is
     * a deletion marker for the override, not a native value sent to Xray.
     */
    private static function mergeSourceOverridePatch(stdClass $base, stdClass $patch): stdClass
    {
        $result = self::cloneValue($base);
        foreach (get_object_vars($patch) as $key => $value) {
            if ($value === null) {
                unset($result->$key);
                continue;
            }
            if ($value instanceof stdClass && ($result->$key ?? null) instanceof stdClass) {
                $result->$key = self::mergeSourceOverridePatch($result->$key, $value);
            } else {
                $result->$key = self::cloneValue($value);
            }
        }
        return $result;
    }

    /** Apply a sparse source override to a freshly generated source config. */
    private static function applySourceOverride(stdClass $base, stdClass $override): stdClass
    {
        $result = self::cloneValue($base);
        foreach (self::SOURCE_OVERRIDE_FIELDS as $field) {
            if (!property_exists($override, $field) || $override->$field === null) {
                continue;
            }
            if ($override->$field instanceof stdClass && ($result->$field ?? null) instanceof stdClass) {
                $result->$field = self::merge($result->$field, $override->$field);
            } else {
                $result->$field = self::cloneValue($override->$field);
            }
        }
        return $result;
    }

    /** Return a sparse override extracted from a full resolved source config. */
    private static function extractSourceOverride(stdClass $resolved, stdClass $base): stdClass
    {
        $resolvedProtocol = strtolower(trim((string) ($resolved->protocol ?? '')));
        $baseProtocol = strtolower(trim((string) ($base->protocol ?? '')));
        if ($resolvedProtocol !== $baseProtocol) {
            self::fail('A source outbound must keep the source protocol');
        }

        $result = new stdClass();
        foreach (self::SOURCE_OVERRIDE_FIELDS as $field) {
            if (!property_exists($resolved, $field)) {
                continue;
            }
            $baseValue = property_exists($base, $field) ? $base->$field : null;
            $different = false;
            $difference = self::sourceDifference(
                $resolved->$field,
                $baseValue,
                $different,
                property_exists($base, $field),
            );
            if ($different) {
                $result->$field = $difference;
            }
        }
        return $result;
    }

    /**
     * Compare only writable source fields.  Missing leaves in a full config
     * are not interpreted as deletions: generated transport fields must never
     * disappear just because an older editor omitted them.
     */
    private static function sourceDifference(
        mixed $value,
        mixed $base,
        bool &$different,
        bool $basePresent = true,
    ): mixed
    {
        if ($value instanceof stdClass) {
            if (!$basePresent || !$base instanceof stdClass) {
                $different = true;
                return self::cloneValue($value);
            }
            $result = new stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $baseHas = property_exists($base, $key);
                $childDifferent = false;
                $child = self::sourceDifference(
                    $item,
                    $baseHas ? $base->$key : null,
                    $childDifferent,
                    $baseHas,
                );
                if ($childDifferent) {
                    $result->$key = $child;
                }
            }
            $different = get_object_vars($result) !== [];
            return $result;
        }
        if (is_array($value)) {
            if (!$basePresent || !is_array($base) || $value !== $base) {
                $different = true;
                return self::cloneValue($value);
            }
            $different = false;
            return [];
        }
        $different = !$basePresent || $value !== $base;
        return self::cloneValue($value);
    }

    /** Existing rows may predate config_override; derive a sparse patch once. */
    private static function sourceOverrideForCandidate(
        ?Outbound $candidate,
        stdClass $base,
        Server $source,
    ): stdClass {
        if ($candidate && $candidate->config_override instanceof stdClass) {
            return self::validateSourceOverride($candidate->config_override, 'config_override');
        }
        if ($candidate && $candidate->config instanceof stdClass
            && (int) ($candidate->source_node_id ?? 0) === (int) $source->id) {
            return self::extractSourceOverride($candidate->config, $base);
        }
        return new stdClass();
    }

    private static function sourceOverrideIsEmpty(stdClass $override): bool
    {
        return get_object_vars($override) === [];
    }

    /** Keep a credential when the admin posts a redacted candidate unchanged. */
    private static function preserveMaskedSecrets(stdClass $next, stdClass $previous): stdClass
    {
        $secretKeys = ['id', 'password', 'pass', 'auth', 'secret', 'privateKey', 'private_key', 'psk'];
        foreach (get_object_vars($previous) as $key => $oldValue) {
            if (in_array($key, $secretKeys, true)
                && (!property_exists($next, $key) || self::containsSecretMask($next->$key))) {
                $next->$key = self::cloneValue($oldValue);
                continue;
            }
            if ($oldValue instanceof stdClass && ($next->$key ?? null) instanceof stdClass) {
                $next->$key = self::preserveMaskedSecrets($next->$key, $oldValue);
            } elseif (is_array($oldValue) && is_array($next->$key ?? null)) {
                foreach ($oldValue as $index => $oldItem) {
                    if (isset($next->{$key}[$index]) && $oldItem instanceof stdClass && $next->{$key}[$index] instanceof stdClass) {
                        $next->{$key}[$index] = self::preserveMaskedSecrets($next->{$key}[$index], $oldItem);
                    }
                }
            }
        }
        return $next;
    }

    private static function containsSecretMask(mixed $value): bool
    {
        if ($value === '***') return true;
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $item) if (self::containsSecretMask($item)) return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) if (self::containsSecretMask($item)) return true;
        }
        return false;
    }

    private static function sourceStreamSettings(Server $source): ?stdClass
    {
        $settings = self::projectedProtocolSettings($source);
        $network = data_get($settings, 'network');
        $networkSettings = data_get($settings, 'network_settings');
        $stream = new stdClass();
        if (is_string($network) && trim($network) !== '') {
            $stream->network = $network;
            if ($networkSettings instanceof stdClass
                || (is_array($networkSettings) && $networkSettings !== [] && !array_is_list($networkSettings))) {
                $key = self::transportKey($network);
                if ($key) $stream->$key = self::cloneValue(self::object($networkSettings, 'network_settings'));
            }
        }

        $serverTls = data_get($settings, 'server_tls');
        $tlsMode = (int) ($serverTls === null ? data_get($settings, 'tls', 0) : $serverTls);
        $tlsSettings = data_get($settings, 'tls_settings');
        $reality = data_get($settings, 'reality_settings');
        if ($tlsMode === 2 || is_array($reality) || $reality instanceof stdClass) {
            $reality = is_array($reality) || $reality instanceof stdClass ? self::object($reality, 'reality_settings') : new stdClass();
            $stream->security = 'reality';
            $stream->realitySettings = self::mapRealitySettings($reality);
        } elseif ($tlsMode > 0) {
            $stream->security = 'tls';
            $tls = is_array($tlsSettings) || $tlsSettings instanceof stdClass
                ? self::object($tlsSettings, 'tls_settings')
                : new stdClass();
            $stream->tlsSettings = self::mapTlsSettings($tls);
        }
        return get_object_vars($stream) === [] ? null : $stream;
    }

    private static function mapTlsSettings(stdClass $settings): stdClass
    {
        $result = new stdClass();
        $map = [
            'server_name' => 'serverName', 'allow_insecure' => 'allowInsecure',
            'alpn' => 'alpn', 'fingerprint' => 'fingerprint',
        ];
        foreach ($map as $from => $to) {
            if (property_exists($settings, $from)) $result->$to = self::cloneValue($settings->$from);
        }
        return $result;
    }

    private static function mapRealitySettings(stdClass $settings): stdClass
    {
        $result = new stdClass();
        $map = [
            'server_name' => 'serverName', 'public_key' => 'publicKey', 'short_id' => 'shortId',
            'fingerprint' => 'fingerprint', 'spider_x' => 'spiderX', 'server_port' => 'serverPort',
        ];
        foreach ($map as $from => $to) {
            if (property_exists($settings, $from)) $result->$to = self::cloneValue($settings->$from);
        }
        return $result;
    }

    private static function transportKey(string $network): ?string
    {
        return match (strtolower(trim($network))) {
            'tcp' => 'tcpSettings', 'kcp' => 'kcpSettings', 'ws' => 'wsSettings',
            'http' => 'httpSettings', 'quic' => 'quicSettings', 'grpc' => 'grpcSettings',
            'httpupgrade' => 'httpupgradeSettings', 'splithttp', 'xhttp' => 'xhttpSettings',
            'h2' => 'httpSettings', default => null,
        };
    }

    private static function makeSourceSnapshot(Server $source, array $credential, stdClass $config): stdClass
    {
        return (object) [
            'source_node_id' => (int) $source->id,
            'source_node_revision' => (int) ($source->config_revision ?? 0),
            'source_node_hash' => self::hash(self::effective($source)),
            'source_protocol' => self::nodeProtocol($source),
            'source_host' => (string) $source->host,
            'source_port' => self::sourcePort($source),
            'credential_fingerprint' => hash('sha256', self::canonicalJson($credential)),
            'config_hash' => self::hash($config),
            'captured_at' => now()->toISOString(),
        ];
    }

    private static function sourceTag(Server $source): string
    {
        return 'source-' . (int) $source->id;
    }

    private static function sourcePort(Server $source): int
    {
        $port = trim((string) ($source->port ?: $source->server_port));
        if ($port === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            self::fail('The source instance must expose one numeric client port');
        }
        return (int) $port;
    }

    private static function credentialString(array $credential, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $credential) && $credential[$key] !== null) {
                return trim((string) $credential[$key]);
            }
        }
        return $default;
    }

    private static function nodeProtocol(Server $node): string
    {
        return Server::normalizeType((string) $node->type) ?: strtolower((string) $node->type);
    }

    private static function applicationReport(mixed $application): mixed
    {
        if ($application instanceof stdClass) {
            $application = get_object_vars($application);
        }
        if (!is_array($application)) return null;
        $allowed = ['desired_revision', 'applied_revision', 'applied_hash', 'status', 'error', 'error_path', 'error_reason', 'error_message', 'effective_hash', 'reported_at'];
        return (object) array_intersect_key($application, array_flip($allowed));
    }

    private static function redactSecrets(mixed $value): mixed
    {
        $secretKeys = ['id', 'password', 'pass', 'auth', 'secret', 'privateKey', 'private_key', 'psk'];
        if ($value instanceof stdClass) {
            $result = new stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $result->$key = in_array($key, $secretKeys, true) ? '***' : self::redactSecrets($item);
            }
            return $result;
        }
        if (is_array($value)) return array_map([self::class, 'redactSecrets'], $value);
        return $value;
    }

    private static function invalidValue(string $field, string $value, array $allowed): never
    {
        self::fail("{$field} must be one of " . implode(', ', $allowed));
    }

    private static function cloneValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $copy = new stdClass();
            foreach (get_object_vars($value) as $key => $item) $copy->$key = self::cloneValue($item);
            return $copy;
        }
        if (is_array($value)) return array_map([self::class, 'cloneValue'], $value);
        return $value;
    }

    private static function objectToArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $key => $item) {
                $result[$key] = self::objectToArray($item);
            }
            return $result;
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::objectToArray($item);
            }
            return $result;
        }
        return $value;
    }

    /** Remove object-property tombstones while retaining array order. */
    private static function withoutTombstones(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $result = new stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                if ($item === null) continue;
                $result->$key = self::withoutTombstones($item);
            }
            return $result;
        }
        if (is_array($value)) return array_map([self::class, 'withoutTombstones'], $value);
        return $value;
    }

    private static function arrayToObject(array $value): stdClass
    {
        $result = new stdClass();
        foreach ($value as $key => $item) $result->$key = is_array($item) && !array_is_list($item)
            ? self::arrayToObject($item)
            : self::cloneValue($item);
        return $result;
    }

    private static function canonicalJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if ($item instanceof stdClass) {
                $fields = get_object_vars($item);
                ksort($fields);
                $out = [];
                foreach ($fields as $key => $field) $out[$key] = $normalize($field);
                return $out;
            }
            if (is_array($item)) return array_map($normalize, $item);
            return $item;
        };
        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
