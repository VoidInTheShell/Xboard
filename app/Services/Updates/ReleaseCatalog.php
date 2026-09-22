<?php
namespace App\Services\Updates;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ReleaseCatalog
{
    // 当前支持的任务协议与状态 schema。协议升级时只抬高当前值，MIN 保持
    // 旧值：旧面板仍可列出并安装声明新协议的版本，由 Release 自带的新
    // Updater 执行，避免协议升级后面板无法通过更新器自举的死锁。
    public const UPDATE_PROTOCOL = 2;
    public const UPDATE_PROTOCOL_MIN = 2;
    public const STATE_SCHEMA = 1;
    public const STATE_SCHEMA_MIN = 1;
    public const PANEL_CONTRACT_MIN = 1;

    public const REPOSITORIES = [
        'xboard' => 'VoidInTheShell/Xboard', 'xboard-admin' => 'VoidInTheShell/xboard-admin',
        'dk_theme' => 'VoidInTheShell/DK_Theme', 'xboard-node' => 'VoidInTheShell/Xboard-Node',
    ];
    public static function channel(?string $version): ?string
    {
        if (preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+\z/', $version ?? '')) return 'stable';
        if (preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+-dev\.[0-9]+\.[0-9]+\z/', $version ?? '')) return 'dev';
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
        // A bounded, cached catalogue; explicit selection is revalidated without
        // the list cache. The longer window keeps repeated "check for updates"
        // clicks from re-fetching GitHub release pages each time.
        return Cache::remember("updates.catalog.{$component}.{$channel}", 300, function () use ($repo, $component, $channel) {
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
        // Manifests are immutable per release, so they are cached: without this
        // cache a single release listing fetched one manifest per version,
        // exhausted the unauthenticated GitHub API quota and made release
        // detection time out for the larger components.
        $url = "https://github.com/{$repo}/releases/download/{$version}/release-manifest.json";
        $manifest = Cache::remember("updates.manifest.{$component}.{$version}", 3600, function () use ($url) {
            $response = Http::connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])->get($url);
            if (!$response->successful() || !is_array($response->json()) || strlen($response->body()) > 262144) {
                // Listing skips this release; exact() surfaces a clear error.
                throw new \UnexpectedValueException('Release manifest unavailable');
            }
            return $response->json();
        });
        if (!is_array($manifest)) throw new \UnexpectedValueException('Invalid manifest');
        $schema = (int) ($manifest['schema_version'] ?? 0);
        $require($schema === 2 && ($manifest['component'] ?? '') === $component
            && ($manifest['repository'] ?? '') === $repo && ($manifest['version'] ?? '') === $version
            && ($manifest['channel'] ?? '') === $channel);
        $require(in_array('linux/amd64', $manifest['platforms'] ?? [], true)
            && in_array('linux/arm64', $manifest['platforms'] ?? [], true));
        // Compatibility is a floor, not an exact match: a release declaring a
        // newer protocol ships the updater that speaks it, so it stays
        // listable and installable through the handoff.
        $require(preg_match('/\A[0-9a-fA-F]{40}\z/', (string) ($manifest['source_commit'] ?? '')) === 1,
            'Invalid source commit');
        $require(($manifest['compatibility']['update_protocol'] ?? null) >= self::UPDATE_PROTOCOL_MIN
            && ($manifest['compatibility']['updater_state_schema'] ?? null) >= self::STATE_SCHEMA_MIN
            && ($manifest['compatibility']['panel_contract'] ?? null) >= self::PANEL_CONTRACT_MIN);

        if ($component === 'xboard-admin') {
            $artifacts = $manifest['artifacts'] ?? [];
            $prefix = "https://github.com/{$repo}/releases/download/{$version}/xboard-updater-";
            $require(($artifacts['admin_image'] ?? '') === "ghcr.io/voidintheshell/xboard-admin:{$version}");
            $require(($artifacts['updater_image'] ?? '') === "ghcr.io/voidintheshell/xboard-admin-updater:{$version}");
            foreach (['amd64', 'arm64'] as $arch) {
                $assetName = "xboard-updater-linux-{$arch}";
                $require(($assets[$assetName]['size'] ?? 0) > 0);
                $require(($artifacts['updater_binaries']["linux/{$arch}"] ?? '') === $prefix . "linux-{$arch}");
            }
        } else {
            $require(($manifest['image'] ?? '') === "ghcr.io/voidintheshell/{$component}:{$version}");
        }
        if ($component === 'xboard-node') {
            foreach (['amd64', 'arm64'] as $arch) foreach (['xboard-node', 'xbctl'] as $name) {
                $require(($assets["{$name}-linux-{$arch}"]['size'] ?? 0) > 0);
                $require(($manifest['binaries'][$arch][$name] ?? '') === "https://github.com/{$repo}/releases/download/{$version}/{$name}-linux-{$arch}");
            }
            $require(($assets['install.sh']['size'] ?? 0) > 0);
        }
        if ($component === 'xboard') {
            $admin = $manifest['components']['xboard-admin'] ?? [];
            $adminVersion = (string) ($admin['version'] ?? '');
            $adminArtifacts = $admin['artifacts'] ?? [];
            $require(self::channel($adminVersion) !== null
                && ($admin['repository'] ?? '') === self::REPOSITORIES['xboard-admin']
                && ($admin['image'] ?? '') === "ghcr.io/voidintheshell/xboard-admin:{$adminVersion}"
                && ($adminArtifacts['admin_image'] ?? '') === $admin['image']
                && ($adminArtifacts['updater_image'] ?? '') === "ghcr.io/voidintheshell/xboard-admin-updater:{$adminVersion}");
            foreach (['amd64', 'arm64'] as $arch) {
                $require(($adminArtifacts['updater_binaries']["linux/{$arch}"] ?? '')
                    === "https://github.com/VoidInTheShell/xboard-admin/releases/download/{$adminVersion}/xboard-updater-linux-{$arch}");
            }
        }
        return ['component' => $component, 'version' => $version, 'channel' => $channel,
            'published_at' => $release['published_at'] ?? null, 'notes' => mb_substr((string) ($release['body'] ?? ''), 0, 16000),
            'components' => $manifest['components'] ?? [], 'manifest' => $manifest];
    }
}
