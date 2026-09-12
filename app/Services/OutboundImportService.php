<?php

namespace App\Services;

use App\Models\Outbound;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\Yaml\Yaml;

class OutboundImportService
{
    private const MAX_SOURCE_BYTES = 4_194_304;
    private const MAX_ITEMS = 200;
    private const MAX_REDIRECTS = 3;

    /** @return array{imported: array<int, array>, skipped: array<int, array>} */
    public function import(string $source): array
    {
        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            XrayConfigService::failAt('source', '导入内容不能超过 4 MiB。', null);
        }
        try {
            $entries = $this->expandSource($source);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            XrayConfigService::failAt('source', Str::limit($e->getMessage(), 160), null);
        }
        $imported = [];
        $skipped = [];

        DB::transaction(function () use ($entries, &$imported, &$skipped) {
            foreach (array_slice($entries, 0, self::MAX_ITEMS) as $entry) {
                try {
                    $parsed = is_array($entry) ? $this->fromClashProxy($entry) : $this->fromUri($entry);
                    $name = $this->uniqueName($parsed['name']);
                    $candidate = new Outbound();
                    $prepared = XrayConfigService::prepareCandidate([
                        'source_type' => Outbound::SOURCE_MANUAL,
                        'config' => $parsed['config'],
                    ]);
                    $candidate->fill(['name' => $name, 'enabled' => true, ...$prepared]);
                    XrayConfigService::preflightOutbound($candidate, collect());
                    $candidate->save();
                    $imported[] = [
                        'id' => (int) $candidate->id,
                        'name' => $candidate->name,
                        'protocol' => $candidate->config->protocol,
                        'tag' => $candidate->config->tag,
                    ];
                } catch (\Throwable $e) {
                    $skipped[] = [
                        'source' => is_string($entry) ? Str::limit($entry, 160) : (string) ($entry['name'] ?? 'Clash 节点'),
                        'message' => $e instanceof ValidationException
                            ? '节点配置未通过校验'
                            : Str::limit($e->getMessage(), 160),
                    ];
                }
            }
        });

        if (count($entries) > self::MAX_ITEMS) {
            $skipped[] = ['source' => '其余条目', 'message' => '一次最多导入 200 个出站'];
        }
        if ($imported === [] && $skipped === []) {
            XrayConfigService::failAt('source', '没有识别到可导入的代理节点。', null);
        }
        return compact('imported', 'skipped');
    }

    /** @return array<int, string|array> */
    private function expandSource(string $source, int $depth = 0): array
    {
        if ($depth > 2) throw new \RuntimeException('订阅嵌套层级过深');
        $source = trim($source);
        if ($source === '') return [];

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $source) ?: [])));
        if (count($lines) === 1 && $this->isSubscriptionUrl($lines[0])) {
            return $this->expandSource($this->fetchSubscription($lines[0]), $depth + 1);
        }

        $yaml = $this->parseClashYaml($source);
        if ($yaml !== []) return $yaml;

        if (count($lines) === 1 && !$this->looksLikeShareUri($source)) {
            $decoded = $this->base64Decode($source);
            if ($decoded !== null && $decoded !== $source) {
                return $this->expandSource($decoded, $depth + 1);
            }
        }

        $expanded = [];
        foreach ($lines as $line) {
            if ($this->isSubscriptionUrl($line)) {
                try {
                    array_push($expanded, ...$this->expandSource($this->fetchSubscription($line), $depth + 1));
                } catch (\Throwable) {
                    $expanded[] = $line;
                }
            } else {
                $expanded[] = $line;
            }
        }
        return $expanded;
    }

    private function fetchSubscription(string $url): string
    {
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $this->assertPublicHttpUrl($url);
            $response = Http::connectTimeout(5)
                ->timeout(20)
                ->withHeaders(['Accept' => 'text/plain, application/yaml, application/json'])
                ->withOptions(['allow_redirects' => false])
                ->get($url);
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if (!$location || $redirect === self::MAX_REDIRECTS) {
                    throw new \RuntimeException('订阅地址重定向次数过多');
                }
                $url = $this->resolveRedirect($url, $location);
                continue;
            }
            $response->throw();
            $this->assertResponseSize($response);
            return $response->body();
        }
        throw new \RuntimeException('无法读取订阅');
    }

    private function assertPublicHttpUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new \RuntimeException('订阅地址只支持 HTTP 或 HTTPS');
        }
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '' || strtolower($host) === 'localhost') throw new \RuntimeException('订阅地址无效');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === []) throw new \RuntimeException('订阅域名无法解析');
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('订阅地址不能指向本机或内网');
            }
        }
    }

    private function assertResponseSize(Response $response): void
    {
        $length = $response->header('Content-Length');
        if (($length !== null && (int) $length > self::MAX_SOURCE_BYTES)
            || strlen($response->body()) > self::MAX_SOURCE_BYTES) {
            throw new \RuntimeException('订阅内容不能超过 4 MiB');
        }
    }

    private function resolveRedirect(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) return $location;
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) $origin .= ':' . $parts['port'];
        if (str_starts_with($location, '/')) return $origin . $location;
        $path = (string) ($parts['path'] ?? '/');
        return $origin . rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $location;
    }

    private function isSubscriptionUrl(string $value): bool
    {
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) return false;
        if (isset($parts['user']) || isset($parts['pass'])) return false;
        return (($parts['path'] ?? '/') !== '/') || isset($parts['query']);
    }

    private function looksLikeShareUri(string $value): bool
    {
        return (bool) preg_match('#^(?:vless|vmess|trojan|ss|socks5?|https?)://#i', trim($value));
    }

    /** @return array<int, array> */
    private function parseClashYaml(string $source): array
    {
        if (!preg_match('/^\s*(?:proxies|Proxy)\s*:/m', $source)) return [];
        try {
            $yaml = Yaml::parse($source);
        } catch (\Throwable) {
            return [];
        }
        $proxies = is_array($yaml) ? ($yaml['proxies'] ?? $yaml['Proxy'] ?? []) : [];
        return is_array($proxies) ? array_values(array_filter($proxies, 'is_array')) : [];
    }

    /** @return array{name:string, config:stdClass} */
    private function fromUri(string $uri): array
    {
        if (!str_contains($uri, '://')) {
            $uri = $this->normalizeProxyPoolLine($uri);
            $scheme = 'http';
        } else {
            $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        }
        return match ($scheme) {
            'vless' => $this->parseVless($uri),
            'vmess' => $this->parseVmess($uri),
            'trojan' => $this->parseTrojan($uri),
            'ss' => $this->parseShadowsocks($uri),
            'socks', 'socks5', 'http', 'https' => $this->parseProxyUrl($uri, $scheme),
            default => throw new \RuntimeException('不支持的节点链接'),
        };
    }

    private function normalizeProxyPoolLine(string $line): string
    {
        if (preg_match('/^([^:\s]+):(\d+):([^:\s]+):(.+)$/', $line, $match)) {
            return 'http://' . rawurlencode($match[3]) . ':' . rawurlencode($match[4])
                . '@' . $match[1] . ':' . $match[2];
        }
        if (preg_match('/^([^:\s]+):([^@\s]+)@(\[[^\]]+\]|[^:\s]+):(\d+)$/', $line, $match)) {
            return 'http://' . rawurlencode($match[1]) . ':' . rawurlencode($match[2])
                . '@' . $match[3] . ':' . $match[4];
        }
        if (preg_match('/^(\[[^\]]+\]|[^:\s]+):(\d+)$/', $line, $match)) {
            return 'http://' . $match[1] . ':' . $match[2];
        }
        throw new \RuntimeException('不支持的代理池条目');
    }

    /** @return array{name:string, config:stdClass} */
    private function parseVless(string $uri): array
    {
        $parts = $this->requiredUrl($uri);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $id = rawurldecode((string) ($parts['user'] ?? ''));
        if ($id === '') throw new \RuntimeException('VLESS 链接缺少 UUID');
        $name = $this->displayName($parts, 'VLESS');
        $user = ['id' => $id, 'encryption' => (string) ($query['encryption'] ?? 'none')];
        if (!empty($query['flow'])) $user['flow'] = (string) $query['flow'];
        return $this->manual($name, 'vless', [
            'vnext' => [[
                'address' => $parts['host'],
                'port' => (int) $parts['port'],
                'users' => [$user],
            ]],
        ], $this->streamSettings($query, $parts));
    }

    /** @return array{name:string, config:stdClass} */
    private function parseTrojan(string $uri): array
    {
        $parts = $this->requiredUrl($uri);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $password = rawurldecode((string) ($parts['user'] ?? ''));
        if ($password === '') throw new \RuntimeException('Trojan 链接缺少密码');
        return $this->manual($this->displayName($parts, 'Trojan'), 'trojan', [
            'servers' => [[
                'address' => $parts['host'],
                'port' => (int) $parts['port'],
                'password' => $password,
            ]],
        ], $this->streamSettings($query, $parts));
    }

    /** @return array{name:string, config:stdClass} */
    private function parseVmess(string $uri): array
    {
        $payload = $this->base64Decode(substr($uri, strlen('vmess://')));
        $data = $payload ? json_decode($payload, true) : null;
        if (!is_array($data) || empty($data['add']) || empty($data['port']) || empty($data['id'])) {
            throw new \RuntimeException('VMess 链接格式无效');
        }
        $query = [
            'type' => $data['net'] ?? 'tcp', 'security' => $data['tls'] ?? 'none',
            'sni' => $data['sni'] ?? null, 'host' => $data['host'] ?? null,
            'path' => $data['path'] ?? null, 'fp' => $data['fp'] ?? null,
        ];
        return $this->manual((string) ($data['ps'] ?? ('VMess ' . $data['add'])), 'vmess', [
            'vnext' => [[
                'address' => (string) $data['add'], 'port' => (int) $data['port'],
                'users' => [[
                    'id' => (string) $data['id'], 'alterId' => (int) ($data['aid'] ?? 0),
                    'security' => (string) ($data['scy'] ?? 'auto'),
                ]],
            ]],
        ], $this->streamSettings($query, ['host' => $data['add']]));
    }

    /** @return array{name:string, config:stdClass} */
    private function parseShadowsocks(string $uri): array
    {
        $fragment = parse_url($uri, PHP_URL_FRAGMENT);
        $withoutFragment = explode('#', substr($uri, strlen('ss://')), 2)[0];
        if (!str_contains($withoutFragment, '@')) {
            $decoded = $this->base64Decode($withoutFragment);
            if (!$decoded) throw new \RuntimeException('Shadowsocks 链接格式无效');
            $withoutFragment = $decoded;
        } else {
            [$credential, $endpoint] = explode('@', $withoutFragment, 2);
            $decodedCredential = $this->base64Decode($credential);
            if ($decodedCredential) $withoutFragment = $decodedCredential . '@' . $endpoint;
        }
        if (!preg_match('/^([^:]+):(.+)@\[?([^\]]+)\]?:([0-9]+)$/', $withoutFragment, $match)) {
            throw new \RuntimeException('Shadowsocks 链接格式无效');
        }
        return $this->manual(rawurldecode((string) ($fragment ?: 'Shadowsocks ' . $match[3])), 'shadowsocks', [
            'servers' => [[
                'address' => $match[3], 'port' => (int) $match[4],
                'method' => rawurldecode($match[1]), 'password' => rawurldecode($match[2]),
            ]],
        ]);
    }

    /** @return array{name:string, config:stdClass} */
    private function parseProxyUrl(string $uri, string $scheme): array
    {
        $parts = $this->requiredUrl($uri);
        $protocol = str_starts_with($scheme, 'socks') ? 'socks' : 'http';
        $server = ['address' => $parts['host'], 'port' => (int) $parts['port']];
        if (isset($parts['user']) || isset($parts['pass'])) {
            $server['users'] = [[
                'user' => rawurldecode((string) ($parts['user'] ?? '')),
                'pass' => rawurldecode((string) ($parts['pass'] ?? '')),
            ]];
        }
        return $this->manual($this->displayName($parts, strtoupper($protocol)), $protocol, ['servers' => [$server]]);
    }

    /** @return array{name:string, config:stdClass} */
    private function fromClashProxy(array $proxy): array
    {
        $type = strtolower((string) ($proxy['type'] ?? ''));
        $name = trim((string) ($proxy['name'] ?? '')) ?: strtoupper($type);
        $server = (string) ($proxy['server'] ?? '');
        $port = (int) ($proxy['port'] ?? 0);
        if ($server === '' || $port < 1) throw new \RuntimeException('Clash 节点缺少地址或端口');

        return match ($type) {
            'socks5', 'socks' => $this->manual($name, 'socks', ['servers' => [[
                'address' => $server, 'port' => $port,
                ...$this->clashUsers($proxy),
            ]]]),
            'http' => $this->manual($name, 'http', ['servers' => [[
                'address' => $server, 'port' => $port,
                ...$this->clashUsers($proxy),
            ]]]),
            'ss' => $this->manual($name, 'shadowsocks', ['servers' => [[
                'address' => $server, 'port' => $port,
                'method' => (string) ($proxy['cipher'] ?? ''), 'password' => (string) ($proxy['password'] ?? ''),
            ]]]),
            'trojan' => $this->manual($name, 'trojan', ['servers' => [[
                'address' => $server, 'port' => $port, 'password' => (string) ($proxy['password'] ?? ''),
            ]]], $this->streamSettings([
                'security' => ($proxy['tls'] ?? true) ? 'tls' : 'none',
                'sni' => $proxy['sni'] ?? null, 'type' => $proxy['network'] ?? 'tcp',
            ], ['host' => $server])),
            'vless' => $this->manual($name, 'vless', ['vnext' => [[
                'address' => $server, 'port' => $port,
                'users' => [[
                    'id' => (string) ($proxy['uuid'] ?? ''),
                    'encryption' => 'none', 'flow' => (string) ($proxy['flow'] ?? ''),
                ]],
            ]]], $this->streamSettings([
                'security' => ($proxy['tls'] ?? false) ? 'tls' : 'none',
                'sni' => $proxy['servername'] ?? $proxy['sni'] ?? null,
                'type' => $proxy['network'] ?? 'tcp', 'path' => $proxy['ws-opts']['path'] ?? null,
                'host' => $proxy['ws-opts']['headers']['Host'] ?? null,
            ], ['host' => $server])),
            'vmess' => $this->manual($name, 'vmess', ['vnext' => [[
                'address' => $server, 'port' => $port,
                'users' => [[
                    'id' => (string) ($proxy['uuid'] ?? ''),
                    'alterId' => (int) ($proxy['alterId'] ?? 0),
                    'security' => (string) ($proxy['cipher'] ?? 'auto'),
                ]],
            ]]], $this->streamSettings([
                'security' => ($proxy['tls'] ?? false) ? 'tls' : 'none',
                'sni' => $proxy['servername'] ?? $proxy['sni'] ?? null,
                'type' => $proxy['network'] ?? 'tcp', 'path' => $proxy['ws-opts']['path'] ?? null,
                'host' => $proxy['ws-opts']['headers']['Host'] ?? null,
            ], ['host' => $server])),
            default => throw new \RuntimeException("暂不支持 Clash {$type} 节点"),
        };
    }

    private function clashUsers(array $proxy): array
    {
        if (!isset($proxy['username']) && !isset($proxy['password'])) return [];
        return ['users' => [[
            'user' => (string) ($proxy['username'] ?? ''),
            'pass' => (string) ($proxy['password'] ?? ''),
        ]]];
    }

    /** @return array{name:string, config:stdClass} */
    private function manual(string $name, string $protocol, array $settings, array $streamSettings = []): array
    {
        $name = trim($name) ?: strtoupper($protocol);
        $tagBase = Str::slug($name) ?: $protocol;
        $tag = substr($tagBase, 0, 40) . '-' . substr(hash('sha256', json_encode([$protocol, $settings, $streamSettings])), 0, 8);
        $config = ['tag' => $tag, 'protocol' => $protocol, 'settings' => $settings];
        if ($streamSettings !== []) $config['streamSettings'] = $streamSettings;
        return ['name' => Str::limit($name, 255, ''), 'config' => XrayConfigService::object($config, 'config')];
    }

    private function streamSettings(array $query, array $parts): array
    {
        $network = strtolower((string) ($query['type'] ?? $query['network'] ?? 'tcp'));
        $security = strtolower((string) ($query['security'] ?? 'none'));
        $stream = ['network' => $network, 'security' => $security === '' ? 'none' : $security];
        if ($network === 'ws') {
            $stream['wsSettings'] = [
                'path' => (string) ($query['path'] ?? '/'),
                'headers' => array_filter(['Host' => (string) ($query['host'] ?? '')]),
            ];
        } elseif ($network === 'grpc') {
            $stream['grpcSettings'] = ['serviceName' => (string) ($query['serviceName'] ?? '')];
        }
        if ($security === 'tls') {
            $stream['tlsSettings'] = array_filter([
                'serverName' => (string) ($query['sni'] ?? $query['serverName'] ?? $parts['host'] ?? ''),
                'fingerprint' => (string) ($query['fp'] ?? ''),
                'alpn' => isset($query['alpn']) ? explode(',', (string) $query['alpn']) : null,
                'allowInsecure' => filter_var($query['allowInsecure'] ?? false, FILTER_VALIDATE_BOOL),
            ], static fn ($value) => $value !== '' && $value !== null && $value !== false);
        } elseif ($security === 'reality') {
            $stream['realitySettings'] = array_filter([
                'serverName' => (string) ($query['sni'] ?? $parts['host'] ?? ''),
                'fingerprint' => (string) ($query['fp'] ?? 'chrome'),
                'publicKey' => (string) ($query['pbk'] ?? ''),
                'shortId' => (string) ($query['sid'] ?? ''),
                'spiderX' => (string) ($query['spx'] ?? ''),
            ], static fn ($value) => $value !== '');
        }
        return $stream;
    }

    private function requiredUrl(string $uri): array
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['port'])) {
            throw new \RuntimeException('节点链接缺少地址或端口');
        }
        return $parts;
    }

    private function displayName(array $parts, string $fallback): string
    {
        return rawurldecode((string) ($parts['fragment'] ?? ''))
            ?: ($fallback . ' ' . $parts['host'] . ':' . $parts['port']);
    }

    private function base64Decode(string $value): ?string
    {
        $value = preg_replace('/\s+/', '', trim($value));
        if ($value === '') return null;
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        return $decoded === false ? null : $decoded;
    }

    private function uniqueName(string $name): string
    {
        $base = Str::limit($name, 240, '');
        $candidate = $base;
        for ($index = 2; Outbound::query()->where('name', $candidate)->exists(); $index++) {
            $candidate = Str::limit($base, 240, '') . " ({$index})";
        }
        return $candidate;
    }
}
