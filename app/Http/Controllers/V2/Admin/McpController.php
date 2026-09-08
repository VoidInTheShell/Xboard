<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\McpKey;
use App\Services\ChangeEventService;
use App\Services\AdminOperationCatalog;
use App\Services\McpKeyService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class McpController extends Controller
{
    public function __construct(
        private readonly McpKeyService $keys,
        private readonly ChangeEventService $changes,
        private readonly AdminOperationCatalog $catalog
    ) {
    }

    public function settings(Request $request)
    {
        if ($request->isMethod('post')) {
            $data = $request->validate(['enabled' => 'required|boolean']);
            $this->keys->setEnabled((bool) $data['enabled']);
        }

        $operations = collect($this->catalog->operations());

        return $this->success([
            'enabled' => $this->keys->enabled(),
            'endpoint' => url('/api/mcp'),
            'protocol_versions' => ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'],
            'change_version' => $this->changes->currentVersion(),
            'coverage' => [
                'operation_count' => $operations->count(),
                'resource_count' => $operations->pluck('resource')->unique()->count(),
                'domains' => $operations->groupBy('domain')->map(fn($items) => [
                    'operation_count' => $items->count(),
                    'resource_count' => $items->pluck('resource')->unique()->count(),
                ])->all(),
            ],
        ]);
    }

    public function keys(Request $request)
    {
        $items = McpKey::query()
            ->where('admin_id', $request->user()->id)
            ->latest('id')
            ->get()
            ->map(fn(McpKey $key) => $this->keys->serialize($key))
            ->values();

        return $this->success($items);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:64',
            'scope' => 'required|in:full,read,custom',
            'domains' => 'array',
            'domains.*' => 'in:infrastructure,accounts,finance,operations,system',
            'client' => 'required|in:codex,claude,cursor,vscode,generic',
            'expires_in_days' => ['nullable', function ($attribute, $value, $fail) {
                if ($value !== 'never' && (!is_numeric($value) || (int) $value < 1 || (int) $value > 3650)) {
                    $fail('有效期必须在 1 到 3650 天之间，或选择永不过期。');
                }
            }],
        ]);

        $created = $this->keys->create($request->user(), $data);
        return $this->success([
            'key' => $this->keys->serialize($created['key']),
            'secret' => $created['secret'],
        ]);
    }

    public function rotate(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer']);
        $key = McpKey::query()->findOrFail($data['id']);
        $created = $this->keys->rotate($request->user(), $key);

        return $this->success([
            'key' => $this->keys->serialize($created['key']),
            'secret' => $created['secret'],
        ]);
    }

    public function revoke(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer']);
        $key = McpKey::query()->findOrFail($data['id']);
        return $this->success($this->keys->serialize($this->keys->revoke($request->user(), $key)));
    }

    public function version(Request $request)
    {
        $since = max(0, (int) $request->input('since', 0));
        return $this->success([
            'version' => $this->changes->currentVersion(),
            'events' => $this->changes->eventsSince($since),
        ]);
    }

    public function events(Request $request): StreamedResponse
    {
        $since = max(0, (int) $request->input('since', 0));

        return response()->stream(function () use ($since) {
            echo "retry: 1000\n\n";

            $events = $this->changes->eventsSince($since, 100);
            foreach ($events as $event) {
                echo 'id: ' . $event['version'] . "\n";
                echo "event: change\n";
                echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            }
            echo 'event: heartbeat' . "\n";
            echo 'data: ' . json_encode(['version' => $this->changes->currentVersion()]) . "\n\n";
            $this->flushStream();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function flushStream(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
