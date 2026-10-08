<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\SubscribeTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

class RemoteSubscribeTemplateService
{
    public const NAMES = ['singbox', 'clash', 'clashmeta', 'stash', 'surge', 'surfboard'];
    private const HISTORY = 'v2_subscribe_template_history';

    public function __construct(private readonly RemoteTemplateDownloader $downloader) {}

    public function settings(string $name): array
    {
        return $this->serialize($this->template($name));
    }

    public function prepare(string $name, string $expected, ?array $settings = null, bool $automatic = false): array
    {
        $row = $this->template($name);
        $this->assertRevision($row, $expected);
        if ($automatic && (!$row->auto_update || !$row->next_update_at || $row->next_update_at->isFuture())) {
            throw new ApiException('此模板尚未到自动更新时间。', 409);
        }
        $url = $settings['url'] ?? $row->remote_url;
        if (!$url) throw new ApiException('请先保存远程模板链接。', 422);
        $lock = Cache::store('redis')->lock('subscribe-template:download:' . $name, 180);
        if (!$lock->get()) throw new ApiException('此模板正在拉取，请稍后重试。', 409);
        try {
            $content = $this->downloader->download($url);
            $this->validateContent($name, $content);
            return [
                'name' => $name, 'expected' => $expected, 'content' => $content,
                'url' => $url, 'auto_update' => $settings['auto_update'] ?? $row->auto_update,
                'interval_hours' => $settings['interval_hours'] ?? $row->interval_hours,
                'source' => $automatic ? 'automatic' : 'remote',
            ];
        } catch (ApiException $e) {
            // Only record an error against the source/revision actually checked.
            // A failed new URL must not overwrite the saved source or schedule.
            if ($url === $row->remote_url) {
                DB::table('v2_subscribe_templates')->where('name', $name)->where('revision', $expected)->update([
                    'last_checked_at' => now(), 'last_error' => $e->getMessage(),
                    'next_update_at' => $row->auto_update ? now()->addHours($row->interval_hours) : null,
                ]);
            }
            throw $e;
        } finally { $lock->release(); }
    }

    public function applyPrepared(array $prepared): array
    {
        return DB::transaction(function () use ($prepared) {
            $row = $this->template($prepared['name'], true);
            $this->assertRevision($row, $prepared['expected']);
            $changed = $this->activate($row, $prepared['content'], $prepared['source']);
            $row->remote_url = $prepared['url'];
            $row->auto_update = $prepared['auto_update'];
            $row->interval_hours = $prepared['interval_hours'];
            $row->last_checked_at = now();
            $row->last_error = null;
            $row->next_update_at = $row->auto_update ? now()->addHours($row->interval_hours) : null;
            $row->revision++;
            $row->save();
            return ['settings' => $this->serialize($row), 'changed' => $changed];
        });
    }

    public function pause(string $name, string $expected, ?int $interval = null): array
    {
        return DB::transaction(function () use ($name, $expected, $interval) {
            $row = $this->template($name, true);
            $this->assertRevision($row, $expected);
            $row->auto_update = false;
            if ($interval !== null) $row->interval_hours = $interval;
            $row->next_update_at = null;
            $row->revision++;
            $row->save();
            return $this->serialize($row);
        });
    }

    public function saveManual(string $name, ?string $content): void
    {
        DB::transaction(function () use ($name, $content) {
            $row = $this->template($name, true);
            $this->activate($row, $content, 'manual');
            // Even an identical manual save invalidates in-flight downloads.
            $row->revision++;
            $row->save();
        });
    }

    public function history(string $name, int $page): array
    {
        $row = $this->template($name);
        $query = DB::table(self::HISTORY)->where('name', $name);
        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / 10));
        $page = min(max(1, $page), $lastPage);
        $items = $query->orderByDesc('id')->offset(($page - 1) * 10)->limit(10)
            ->get(['id', 'created_at', 'source', 'bytes'])->map(fn($item) => [
                'id' => (int) $item->id, 'created_at' => \Carbon\Carbon::parse($item->created_at)->toIso8601String(),
                'source' => $item->source, 'bytes' => (int) $item->bytes,
                'current' => (int) $row->current_history_id === (int) $item->id,
            ])->all();
        return ['items' => $items, 'current_page' => $page, 'last_page' => $lastPage, 'total' => $total];
    }

    public function historyContent(string $name, int $id): string
    {
        $this->assertName($name);
        $item = DB::table(self::HISTORY)->where('name', $name)->where('id', $id)->first();
        if (!$item) throw new ApiException('历史模板不存在。', 404);
        return $item->content;
    }

    public function restore(string $name, int $id, string $expected, bool $pause): array
    {
        return DB::transaction(function () use ($name, $id, $expected, $pause) {
            $row = $this->template($name, true);
            $this->assertRevision($row, $expected);
            if ((int) $row->current_history_id === $id) throw new ApiException('这份模板已经是当前版本。', 422);
            $this->activate($row, $this->historyContent($name, $id), 'restore', true);
            if ($pause) { $row->auto_update = false; $row->next_update_at = null; }
            $row->revision++;
            $row->save();
            return $this->serialize($row);
        });
    }

    public function drop(string $name, int $id, string $expected): void
    {
        DB::transaction(function () use ($name, $id, $expected) {
            $row = $this->template($name, true);
            $this->assertRevision($row, $expected);
            if ((int) $row->current_history_id === $id) throw new ApiException('当前模板不可删除。', 422);
            if (!DB::table(self::HISTORY)->where('name', $name)->where('id', $id)->delete()) throw new ApiException('历史模板不存在。', 404);
            $row->revision++;
            $row->save();
        });
    }

    public function validateContent(string $name, string $content): void
    {
        if (strlen($content) > RemoteTemplateDownloader::MAX_BYTES) throw new ApiException('远程模板不能超过 2 MiB。', 422);
        if (trim($content) === '' || str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8')) {
            throw new ApiException('远程模板必须是非空的 UTF-8 文本。', 422);
        }
        try {
            if ($name === 'singbox') {
                $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($parsed) || !is_array($parsed['outbounds'] ?? null) || !array_is_list($parsed['outbounds'])) throw new \RuntimeException();
                foreach ($parsed['outbounds'] as $outbound) {
                    if (!is_array($outbound) || !is_string($outbound['type'] ?? null)) throw new \RuntimeException();
                }
            } elseif (in_array($name, ['clash', 'clashmeta', 'stash'], true)) {
                $parsed = Yaml::parse($content);
                if (!is_array($parsed) || !is_array($parsed['proxies'] ?? null) || !is_array($parsed['proxy-groups'] ?? null)) throw new \RuntimeException();
                if (!array_is_list($parsed['proxies']) || !array_is_list($parsed['proxy-groups'])) throw new \RuntimeException();
                foreach ($parsed['proxy-groups'] as $group) {
                    if (!is_array($group) || !is_string($group['name'] ?? null) || !is_array($group['proxies'] ?? null)) throw new \RuntimeException();
                }
            } else {
                foreach (['General', 'Proxy', 'Proxy Group', 'Rule'] as $section) {
                    if (!preg_match('/^\s*\[' . preg_quote($section, '/') . '\]\s*$/m', $content)) throw new \RuntimeException();
                }
                if (!str_contains($content, '$proxies') || !str_contains($content, '$proxy_group')) throw new \RuntimeException();
            }
        } catch (\Throwable) {
            throw new ApiException('远程内容不是可用的 ' . $name . ' 订阅模板，请检查格式及节点注入字段。', 422);
        }
    }

    private function template(string $name, bool $lock = false): SubscribeTemplate
    {
        $this->assertName($name);
        $query = SubscribeTemplate::where('name', $name);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$row) throw new ApiException('模板尚未初始化，请先完成数据库升级。', 409);
        return $row;
    }

    private function assertName(string $name): void
    {
        if (!in_array($name, self::NAMES, true)) throw new ApiException('不支持的订阅模板格式。', 422);
    }

    private function assertRevision(SubscribeTemplate $row, string $expected): void
    {
        if ((string) $row->revision !== $expected) throw new ApiException('模板或配置已更新，请重新读取后重试。', 409);
    }

    private function activate(SubscribeTemplate $row, ?string $content, string $source, bool $forceHistory = false): bool
    {
        $before = $this->effective($row->name, $row->content);
        $after = $this->effective($row->name, $content);
        // Include edits made by older backends after migration but before this upgrade.
        $current = DB::table(self::HISTORY)->where('id', $row->current_history_id)->where('name', $row->name)->value('content');
        if ($current !== $before) $row->current_history_id = $this->record($row->name, $before, 'manual');
        if ($before === $after && !$forceHistory) { $row->content = $content; return false; }
        $row->current_history_id = $this->record($row->name, $after, $source);
        $row->content = $content;
        $row->last_updated_at = now();
        $name = $row->name;
        DB::afterCommit(fn() => SubscribeTemplate::flushCache($name));
        return $before !== $after;
    }

    private function effective(string $name, ?string $content): string
    {
        return $content !== null && trim($content) !== '' ? $content : (SubscribeTemplate::defaultContent($name) ?? '');
    }

    private function record(string $name, string $content, string $source): int
    {
        return DB::table(self::HISTORY)->insertGetId([
            'name' => $name, 'content' => $content, 'source' => $source,
            'bytes' => strlen($content), 'created_at' => now(),
        ]);
    }

    private function serialize(SubscribeTemplate $row): array
    {
        return [
            'name' => $row->name, 'url' => $row->remote_url ?? '', 'auto_update' => (bool) $row->auto_update,
            'interval_hours' => (int) $row->interval_hours, 'revision' => (string) $row->revision,
            'last_checked_at' => $row->last_checked_at?->toIso8601String(),
            'last_updated_at' => $row->last_updated_at?->toIso8601String(),
            'next_update_at' => $row->next_update_at?->toIso8601String(), 'last_error' => $row->last_error,
        ];
    }
}
