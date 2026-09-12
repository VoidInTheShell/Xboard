<?php

namespace App\Services;

use App\Models\Server;
use App\Models\XrayRuleFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class XrayRuleFileService
{
    private const BUILT_INS = [
        [
            'name' => 'geoip.dat',
            'url' => 'https://github.com/Loyalsoldier/v2ray-rules-dat/releases/latest/download/geoip.dat',
        ],
        [
            'name' => 'geosite.dat',
            'url' => 'https://github.com/Loyalsoldier/v2ray-rules-dat/releases/latest/download/geosite.dat',
        ],
    ];

    public function files(Server $node): array
    {
        $this->assertSupported($node);
        $this->ensureBuiltIns($node);
        return XrayRuleFile::query()->where('server_id', $node->id)
            ->orderByDesc('built_in')->orderBy('id')->get()
            ->map(fn (XrayRuleFile $file) => $this->snapshot($file))->all();
    }

    public function desired(Server $node): array
    {
        $this->ensureBuiltIns($node);
        return XrayRuleFile::query()->where('server_id', $node->id)->orderBy('id')->get()->map(
            fn (XrayRuleFile $file) => [
                'id' => (int) $file->id,
                'name' => $file->name,
                'source' => $file->source,
                'url' => $file->url,
                'auto_update' => (bool) $file->auto_update,
                'update_interval_hours' => (int) $file->update_interval_hours,
                'download_revision' => (int) $file->download_revision,
            ],
        )->all();
    }

    public function validateInput(Server $node, array $input, ?XrayRuleFile $existing = null): array
    {
        $this->assertSupported($node);
        if ($existing && (int) $existing->server_id !== (int) $node->id) {
            XrayConfigService::failAt('rule_file.id', '规则文件不属于当前节点。', null);
        }

        $name = trim((string) ($input['name'] ?? $existing?->name ?? ''));
        if ($name === '' || strlen($name) > 255 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)
            || basename($name) !== $name || in_array($name, ['.', '..'], true)) {
            XrayConfigService::failAt('rule_file.name', '文件名只能包含字母、数字、点、下划线和连字符。', null);
        }

        $source = (string) ($input['source'] ?? $existing?->source ?? 'remote');
        if (!in_array($source, ['remote', 'node-default', 'managed'], true)) {
            XrayConfigService::failAt('rule_file.source', '规则文件来源无效。', null);
        }
        if (!$existing && $source !== 'remote') {
            XrayConfigService::failAt('rule_file.source', '新增规则文件必须使用远程下载地址。', null);
        }
        if ($existing?->read_only) {
            $name = $existing->name;
            $source = $existing->source;
        }

        $url = $existing?->read_only ? $existing->url : ($input['url'] ?? $existing?->url);
        $url = is_string($url) ? trim($url) : null;
        if ($source === 'remote' || $source === 'node-default') {
            if (!$url) XrayConfigService::failAt('rule_file.url', '请填写规则文件下载地址。', null);
            $this->assertSafeUrl($url);
        } else {
            $url = null;
        }

        $autoUpdate = array_key_exists('auto_update', $input)
            ? (bool) $input['auto_update']
            : (bool) ($existing?->auto_update ?? true);
        $hours = (int) ($input['update_interval_hours'] ?? $existing?->update_interval_hours ?? 24);
        if ($hours < 1 || $hours > 8760) {
            XrayConfigService::failAt('rule_file.update_interval_hours', '自动更新间隔必须在 1 到 8760 小时之间。', null);
        }

        return compact('name', 'source', 'url') + [
            'auto_update' => $autoUpdate,
            'update_interval_hours' => $hours,
        ];
    }

    public function save(Server $node, array $input, ?XrayRuleFile $existing = null): XrayRuleFile
    {
        $data = $this->validateInput($node, $input, $existing);
        return DB::transaction(function () use ($node, $data, $existing) {
            $file = $existing
                ? XrayRuleFile::query()->lockForUpdate()->findOrFail($existing->id)
                : new XrayRuleFile(['server_id' => $node->id]);
            $downloadChanged = $file->exists && (
                $file->name !== $data['name']
                || $file->source !== $data['source']
                || $file->url !== $data['url']
            );
            $file->fill($data);
            if (!$file->exists) {
                $file->built_in = false;
                $file->read_only = false;
                $file->download_revision = 1;
            } elseif ($downloadChanged) {
                $file->download_revision = (int) $file->download_revision + 1;
                $file->status = 'pending';
                $file->error = null;
            }
            $file->save();
            $this->advanceNode($node);
            return $file->fresh();
        });
    }

    public function requestDownload(Server $node, XrayRuleFile $file): XrayRuleFile
    {
        if ((int) $file->server_id !== (int) $node->id || !$file->url) {
            XrayConfigService::failAt('rule_file.id', '该规则文件不能远程更新。', null);
        }
        $this->assertSafeUrl($file->url);
        return DB::transaction(function () use ($node, $file) {
            $file = XrayRuleFile::query()->lockForUpdate()->findOrFail($file->id);
            $file->download_revision = (int) $file->download_revision + 1;
            $file->status = 'pending';
            $file->error = null;
            $file->save();
            $this->advanceNode($node);
            return $file->fresh();
        });
    }

    public function drop(Server $node, XrayRuleFile $file): void
    {
        if ((int) $file->server_id !== (int) $node->id) {
            XrayConfigService::failAt('rule_file.id', '规则文件不属于当前节点。', null);
        }
        if ($file->built_in || $file->read_only) {
            XrayConfigService::failAt('rule_file.id', '内置规则文件不能删除。', null);
        }
        DB::transaction(function () use ($node, $file) {
            $file->delete();
            $this->advanceNode($node);
        });
    }

    public function recordReportedState(Server $node, array $reported): void
    {
        foreach ($reported as $item) {
            if (!is_array($item) || !isset($item['id']) || !is_numeric($item['id'])) continue;
            $updates = [];
            if (isset($item['size']) && is_numeric($item['size'])) $updates['size'] = max(0, (int) $item['size']);
            if (isset($item['updated_at']) && is_numeric($item['updated_at'])) $updates['file_updated_at'] = max(0, (int) $item['updated_at']);
            if (isset($item['status']) && is_string($item['status']) && in_array($item['status'], ['ready', 'pending', 'failed', 'missing'], true)) {
                $updates['status'] = $item['status'];
                if ($item['status'] === 'ready') $updates['error'] = null;
            }
            if (array_key_exists('error', $item)) {
                $updates['error'] = is_string($item['error']) ? mb_substr($item['error'], 0, 1000) : null;
            }
            if ($updates !== []) {
                XrayRuleFile::query()->where('server_id', $node->id)->where('id', (int) $item['id'])->update($updates);
            }
        }
    }

    public function snapshot(XrayRuleFile $file): array
    {
        return [
            'id' => (int) $file->id,
            'name' => $file->name,
            'size' => (int) ($file->size ?? 0),
            'updated_at' => $file->file_updated_at
                ? Carbon::createFromTimestamp($file->file_updated_at)->toIso8601String()
                : null,
            'source' => $file->source,
            'url' => $file->url,
            'auto_update' => (bool) $file->auto_update,
            'update_interval_hours' => (int) $file->update_interval_hours,
            'built_in' => (bool) $file->built_in,
            'read_only' => (bool) $file->read_only,
            'downloadable' => (bool) $file->url,
            'status' => $file->status,
            'error' => $file->error,
        ];
    }

    private function ensureBuiltIns(Server $node): void
    {
        foreach (self::BUILT_INS as $builtIn) {
            XrayRuleFile::query()->firstOrCreate(
                ['server_id' => $node->id, 'name' => $builtIn['name']],
                [
                    'source' => 'node-default', 'url' => $builtIn['url'],
                    'auto_update' => true, 'update_interval_hours' => 24,
                    'built_in' => true, 'read_only' => true,
                    'download_revision' => 1, 'status' => 'pending',
                ],
            );
        }
    }

    private function advanceNode(Server $node): void
    {
        Server::query()->whereKey($node->id)->increment('config_revision');
        $node->config_revision = (int) ($node->config_revision ?? 0) + 1;
    }

    private function assertSupported(Server $node): void
    {
        if (!XrayConfigService::supports($node)) {
            XrayConfigService::failAt('node_id', '当前节点不能使用 Xray 规则文件。', null);
        }
    }

    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            XrayConfigService::failAt('rule_file.url', '下载地址必须是公开的 HTTP 或 HTTPS URL。', null);
        }
        $host = trim((string) $parts['host'], '[]');
        if (strtolower($host) === 'localhost') {
            XrayConfigService::failAt('rule_file.url', '下载地址不能指向本机或内网。', null);
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === []) {
            XrayConfigService::failAt('rule_file.url', '下载域名无法解析。', null);
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                XrayConfigService::failAt('rule_file.url', '下载地址不能指向本机或内网。', null);
            }
        }
    }
}
