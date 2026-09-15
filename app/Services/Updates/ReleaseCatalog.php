<?php
namespace App\Services\Updates;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ReleaseCatalog
{
    public const REPOSITORIES = [
        'xboard' => 'VoidInTheShell/Xboard', 'xboard-admin' => 'VoidInTheShell/xboard-admin',
        'dk_theme' => 'VoidInTheShell/DK_Theme', 'xboard-node' => 'VoidInTheShell/Xboard-Node',
    ];
    public static function channel(?string $version): ?string
    {
        if (preg_match('/\\Av[0-9]+\\.[0-9]+\\.[0-9]+\\z/', $version ?? '')) return 'stable';
        if (preg_match('/\\Av[0-9]+\\.[0-9]+\\.[0-9]+-dev\\.[0-9]+\\.[0-9]+\\z/', $version ?? '')) return 'dev';
        return null;
    }
    private function get(string $url): array
    {
        $response = Http::connectTimeout(5)->timeout(20)->acceptJson()
            ->withHeaders(['User-Agent' => 'Xboard-Update-Catalog', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->withOptions(['allow_redirects' => false])->get($url);
        if (!$response->successful() || strlen($response->body()) > 4 * 1024 * 1024 || !is_array($response->json())) {
            throw new ApiException('无法读取自有仓库的版本目录，请稍后重试。', 502);
        }
        return $response->json();
    }
    public function releases(string $component, string $channel): array
    {
        if (!isset(self::REPOSITORIES[$component]) || !in_array($channel, ['stable', 'dev'], true)) {
            throw new ApiException('无效的组件或版本分支。', 422);
        }
        $repo = self::REPOSITORIES[$component];
        // A bounded, cached catalogue; explicit selection is revalidated without the list cache.
        return Cache::remember("updates.catalog.{$component}.{$channel}", 60, function () use ($repo, $component, $channel) {
            $items = [];
            for ($page = 1; $page <= 10; $page++) {
                $batch = $this->get("https://api.github.com/repos/{$repo}/releases?per_page=100&page={$page}");
                foreach ($batch as $release) {
                    if (($release['draft'] ?? true) || self::channel($release['tag_name'] ?? null) !== $channel
                        || (bool) ($release['prerelease'] ?? false) !== ($channel === 'dev')) continue;
                    try { $items[] = $this->read($component, $release); }
                    catch (\UnexpectedValueException) { /* Incomplete or invalid releases are not installable. */ }
                    if (count($items) >= 50) break 2;
                }
                if (count($batch) < 100) break;
            }
            usort($items, fn ($a, $b) => version_compare($b['version'], $a['version']));
            return $items;
        });
    }
    public function exact(string $component, string $version): array
    {
        if (!isset(self::REPOSITORIES[$component]) || !self::channel($version)) throw new ApiException('无效的准确版本。', 422);
        $repo = self::REPOSITORIES[$component];
        try {
            $release = $this->get("https://api.github.com/repos/{$repo}/releases/tags/{$version}");
            if (($release['tag_name'] ?? null) !== $version) throw new \UnexpectedValueException('Release tag mismatch');
            return $this->read($component, $release);
        } catch (\UnexpectedValueException $error) {
            throw new ApiException('版本发布不完整或清单不符合更新协议。', 422);
        }
    }
    private function read(string $component, array $release): array
    {
        $repo = self::REPOSITORIES[$component];
        $version = $release['tag_name'] ?? '';
        $channel = self::channel($version);
        $require = static function ($valid) { if (!$valid) throw new \UnexpectedValueException('Invalid release'); };
        $require($channel && !($release['draft'] ?? true) && (bool) ($release['prerelease'] ?? false) === ($channel === 'dev'));
        $assets = array_column($release['assets'] ?? [], null, 'name');
        $asset = $assets['release-manifest.json'] ?? [];
        $require(($asset['size'] ?? 0) > 0 && ($asset['size'] ?? 0) <= 262144);
        // Derive a trusted URL; never fetch a URL supplied by a manifest.
        $url = "https://github.com/{$repo}/releases/download/{$version}/release-manifest.json";
        $response = Http::connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])->get($url);
        if (!$response->successful()) throw new ApiException('读取版本清单失败，请重试。', 502);
        $manifest = $response->json();
        $require(strlen($response->body()) <= 262144 && is_array($manifest));
        $require(($manifest['schema_version'] ?? null) === 1 && ($manifest['component'] ?? '') === $component
            && ($manifest['repository'] ?? '') === $repo && ($manifest['version'] ?? '') === $version
            && ($manifest['channel'] ?? '') === $channel
            && ($manifest['image'] ?? '') === "ghcr.io/voidintheshell/{$component}:{$version}"
            && ($manifest['compatibility']['update_protocol'] ?? null) === 1
            && ($manifest['compatibility']['panel_contract'] ?? null) === 1);
        $require(in_array('linux/amd64', $manifest['platforms'] ?? [], true) && in_array('linux/arm64', $manifest['platforms'] ?? [], true));
        if ($component === 'xboard-node') {
            foreach (['amd64', 'arm64'] as $arch) foreach (['xboard-node', 'xbctl'] as $name) {
                $require(($assets["{$name}-linux-{$arch}"]['size'] ?? 0) > 0);
                $require(($manifest['binaries'][$arch][$name] ?? '') === "https://github.com/{$repo}/releases/download/{$version}/{$name}-linux-{$arch}");
            }
            $require(($assets['install.sh']['size'] ?? 0) > 0);
        }
        return ['component' => $component, 'version' => $version, 'channel' => $channel,
            'published_at' => $release['published_at'] ?? null, 'notes' => mb_substr((string) ($release['body'] ?? ''), 0, 16000),
            'components' => [], 'manifest' => $manifest];
    }
}
