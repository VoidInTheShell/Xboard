<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FallbackSiteService
{
    public const MAX_PAGE_BYTES = 512 * 1024;
    public const MAX_RAW_BYTES = 64 * 1024;

    private const TEMPLATES = [
        'portal' => [
            'name' => '服务门户',
            'description' => '简洁的企业服务入口，适合通用节点域名。',
            'file' => 'portal.html',
        ],
        'status' => [
            'name' => '系统状态',
            'description' => '低调的服务状态页，适合基础设施域名。',
            'file' => 'status.html',
        ],
        'docs' => [
            'name' => '文档中心',
            'description' => '轻量文档首页，适合内容与技术服务域名。',
            'file' => 'docs.html',
        ],
    ];

    public static function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'mode' => 'builtin',
            'template' => 'portal',
        ];
    }

    public function templates(): array
    {
        return collect(self::TEMPLATES)->map(function (array $template, string $id) {
            return [
                'id' => $id,
                'name' => $template['name'],
                'description' => $template['description'],
            ];
        })->values()->all();
    }

    public function supportsDefault(Server $node): bool
    {
        return in_array(Server::normalizeType((string) $node->type), [Server::TYPE_VLESS, Server::TYPE_TROJAN], true)
            && $this->xrayFallbackCompatible($node);
    }

    public function upload(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if (!is_string($content) || $content === '' || strlen($content) > self::MAX_PAGE_BYTES) {
            $this->fail('page', '页面必须是 512 KiB 以内的非空 HTML 文件。');
        }
        if (str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8')) {
            $this->fail('page', '页面必须是 UTF-8 编码的 HTML 文本。');
        }
        if (!preg_match('/<(?:!doctype\s+html|html|head|body)\b/i', $content)) {
            $this->fail('page', '文件内容不是可识别的 HTML 页面。');
        }

        $filename = hash('sha256', $content) . '.html';
        Storage::disk('local')->put('fallback-sites/' . $filename, $content);

        return [
            'asset' => $filename,
            'name' => $file->getClientOriginalName(),
            'size' => strlen($content),
        ];
    }

    public function validate(Server $node): void
    {
        $config = $node->fallback_site;
        if (!is_array($config) || !($config['enabled'] ?? false)) {
            return;
        }

        $mode = strtolower(trim((string) ($config['mode'] ?? 'builtin')));
        if (!in_array($mode, ['builtin', 'upload', 'proxy', 'raw'], true)) {
            $this->fail('fallback_site.mode', '请选择有效的回落方式。');
        }

        $protocol = Server::normalizeType((string) $node->type);
        if (in_array($protocol, [Server::TYPE_VLESS, Server::TYPE_TROJAN], true)) {
            if (!$this->xrayFallbackCompatible($node)) {
                $this->fail(
                    'fallback_site.enabled',
                    'VLESS/Trojan 回落只适用于 Xray 的 TCP + TLS 入站；REALITY 的目标站点请在入站安全配置中设置。'
                );
            }
        } elseif ($protocol === Server::TYPE_HYSTERIA
            && (int) data_get($node->protocol_settings, 'version', 2) === 2
            && $node->xray_config === null) {
            // Legacy sing-box Hysteria2 nodes consume the same high-level
            // contract as an inline response or reverse-proxy masquerade.
        } else {
            $this->fail('fallback_site.enabled', '当前协议或运行内核不支持回落站点。');
        }

        match ($mode) {
            'builtin' => $this->validateTemplate((string) ($config['template'] ?? '')),
            'upload' => $this->validateAsset((string) ($config['asset'] ?? '')),
            'proxy' => $this->validateUpstream((array) ($config['upstream'] ?? [])),
            'raw' => $this->validateRaw(
                $config['raw'] ?? null,
                in_array($protocol, [Server::TYPE_VLESS, Server::TYPE_TROJAN], true),
            ),
        };
    }

    public function resolve(Server $node): ?array
    {
        $config = $node->fallback_site;
        if (!is_array($config) || !($config['enabled'] ?? false)) {
            return null;
        }

        $this->validate($node);
        $mode = strtolower(trim((string) ($config['mode'] ?? 'builtin')));
        $resolved = [
            'enabled' => true,
            'mode' => $mode,
        ];

        if ($mode === 'builtin') {
            $template = (string) ($config['template'] ?? 'portal');
            $resolved['content'] = $this->templateContent($template);
            $resolved['content_type'] = 'text/html; charset=utf-8';
        } elseif ($mode === 'upload') {
            $resolved['content'] = Storage::disk('local')->get($this->assetPath((string) $config['asset']));
            $resolved['content_type'] = 'text/html; charset=utf-8';
        } elseif ($mode === 'proxy') {
            $resolved['upstream'] = [
                'host' => trim((string) data_get($config, 'upstream.host')),
                'port' => (int) data_get($config, 'upstream.port'),
                'scheme' => (string) data_get($config, 'upstream.scheme', 'auto'),
            ];
        } else {
            $resolved['raw'] = $config['raw'];
        }

        return $resolved;
    }

    private function xrayFallbackCompatible(Server $node): bool
    {
        $settings = $node->protocol_settings ?? [];
        $native = $node->xray_config;
        $network = strtolower(trim((string) data_get($native, 'inbounds.0.streamSettings.network', data_get($settings, 'network', 'tcp'))));
        $security = strtolower(trim((string) data_get($native, 'inbounds.0.streamSettings.security', '')));
        if ($security === '') {
            $security = match ((int) data_get($settings, 'server_tls', data_get($settings, 'tls', 0))) {
                1 => 'tls',
                2 => 'reality',
                default => 'none',
            };
        }

        return in_array($network ?: 'tcp', ['tcp', 'raw'], true) && $security === 'tls';
    }

    private function validateTemplate(string $template): void
    {
        if (!isset(self::TEMPLATES[$template])) {
            $this->fail('fallback_site.template', '请选择有效的内置模板。');
        }
    }

    private function validateAsset(string $asset): void
    {
        $path = $this->assetPath($asset);
        if (!Storage::disk('local')->exists($path)) {
            $this->fail('fallback_site.asset', '上传页面不存在，请重新上传。');
        }
    }

    private function validateUpstream(array $upstream): void
    {
        $host = trim((string) ($upstream['host'] ?? ''));
        $port = (int) ($upstream['port'] ?? 0);
        $scheme = strtolower(trim((string) ($upstream['scheme'] ?? 'auto')));
        $domain = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i';
        if ($host === '' || (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match($domain, $host))) {
            $this->fail('fallback_site.upstream.host', '请输入有效的 IP 地址或域名。');
        }
        if ($port < 1 || $port > 65535) {
            $this->fail('fallback_site.upstream.port', '端口必须在 1 到 65535 之间。');
        }
        if (!in_array($scheme ?: 'auto', ['auto', 'http', 'https'], true)) {
            $this->fail('fallback_site.upstream.scheme', '请选择有效的连接方式。');
        }
    }

    private function validateRaw(mixed $raw, bool $xray): void
    {
        if (!is_array($raw) || $raw === []) {
            $this->fail('fallback_site.raw', '原始配置必须是非空的 JSON 对象或数组。');
        }
        if ($xray && !array_is_list($raw)) {
            $this->fail('fallback_site.raw', 'Xray 原始回落配置必须是 JSON 数组。');
        }
        $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > self::MAX_RAW_BYTES) {
            $this->fail('fallback_site.raw', '原始配置不能超过 64 KiB。');
        }
    }

    private function templateContent(string $template): string
    {
        $this->validateTemplate($template);
        $content = file_get_contents(resource_path('fallback-sites/' . self::TEMPLATES[$template]['file']));
        if (!is_string($content)) {
            throw new \RuntimeException('Fallback template cannot be read.');
        }
        return $content;
    }

    private function assetPath(string $asset): string
    {
        if (!preg_match('/^[a-f0-9]{64}\.html$/', $asset)) {
            $this->fail('fallback_site.asset', '上传页面标识无效。');
        }
        return 'fallback-sites/' . $asset;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
