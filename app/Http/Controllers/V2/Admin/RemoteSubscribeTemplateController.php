<?php

namespace App\Http\Controllers\V2\Admin;

use App\Contracts\PreparesAdminMutation;
use App\Http\Controllers\Controller;
use App\Services\RemoteSubscribeTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RemoteSubscribeTemplateController extends Controller implements PreparesAdminMutation
{
    public function __construct(private readonly RemoteSubscribeTemplateService $templates) {}

    public function prepareMutation(Request $request): void
    {
        $action = $request->route()->getActionMethod();
        if (!in_array($action, ['save', 'refresh'], true)) return;
        $data = $this->validateInput($request, true, $action === 'save' ? [
            'url' => ['required', 'string', 'max:4096'],
            'auto_update' => ['required', 'boolean'],
            'interval_hours' => ['required', 'integer', 'between:1,720'],
        ] : []);
        $settings = $action === 'save' ? [
            'url' => trim($data['url']), 'auto_update' => (bool) $data['auto_update'],
            'interval_hours' => (int) $data['interval_hours'],
        ] : null;
        $request->attributes->set('remote_template_prepared', $this->templates->prepare($data['name'], $data['expected_revision'], $settings));
    }

    public function fetch(Request $request)
    {
        $data = $this->validateInput($request);
        return $this->success($this->templates->settings($data['name']));
    }

    public function save(Request $request)
    {
        return $this->success($this->templates->applyPrepared($request->attributes->get('remote_template_prepared')));
    }

    public function refresh(Request $request) { return $this->save($request); }

    public function pause(Request $request)
    {
        $data = $this->validateInput($request, true, ['interval_hours' => ['sometimes', 'integer', 'between:1,720']]);
        return $this->success($this->templates->pause($data['name'], $data['expected_revision'], isset($data['interval_hours']) ? (int) $data['interval_hours'] : null));
    }

    public function history(Request $request)
    {
        $data = $this->validateInput($request, false, ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        return $this->success($this->templates->history($data['name'], (int) ($data['page'] ?? 1)));
    }

    public function historyDetail(Request $request)
    {
        $data = $this->validateInput($request, false, ['id' => ['required', 'integer', 'min:1']]);
        return $this->success(['content' => $this->templates->historyContent($data['name'], (int) $data['id'])]);
    }

    public function restore(Request $request)
    {
        $data = $this->validateInput($request, true, [
            'id' => ['required', 'integer', 'min:1'], 'pause_auto_update' => ['sometimes', 'boolean'],
        ]);
        return $this->success($this->templates->restore($data['name'], (int) $data['id'], $data['expected_revision'], (bool) ($data['pause_auto_update'] ?? true)));
    }

    public function drop(Request $request)
    {
        $data = $this->validateInput($request, true, ['id' => ['required', 'integer', 'min:1']]);
        $this->templates->drop($data['name'], (int) $data['id'], $data['expected_revision']);
        return $this->success(true);
    }

    private function validateInput(Request $request, bool $mutation = false, array $extra = []): array
    {
        return $request->validate([
            'name' => ['required', Rule::in(RemoteSubscribeTemplateService::NAMES)],
            ...($mutation ? ['expected_revision' => ['required', 'string', 'regex:/^[1-9][0-9]{0,17}$/D']] : []),
            ...$extra,
        ]);
    }
}
