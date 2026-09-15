<?php
namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\UpdateTask;
use App\Services\Updates\UpdateManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateController extends Controller
{
    public function __construct(private readonly UpdateManager $updates) {}
    private function target(Request $request): array
    {
        return $request->validate(['target_kind' => ['required', Rule::in(['panel', 'node'])],
            'component' => ['required', Rule::in(['xboard', 'xboard-admin', 'dk_theme', 'xboard-node'])],
            'machine_id' => 'required_if:target_kind,node|nullable|integer|min:1',
            'instance_id' => 'required_if:target_kind,node|nullable|string|max:80',
            'channel' => ['required', Rule::in(['stable', 'dev'])]]);
    }
    public function overview(Request $request)
    {
        $data = $request->validate(['target_kind' => ['required', Rule::in(['panel', 'node'])]]);
        return $this->success($this->updates->overview($data['target_kind']));
    }
    public function releases(Request $request) { return $this->success($this->updates->releases($this->target($request))); }
    public function create(Request $request)
    {
        $data = $this->target($request) + $request->validate(['target_version' => 'required|string|max:100',
            'idempotency_key' => 'required|string|min:16|max:160']);
        return $this->success($this->updates->create($data, (int) $request->user()->id)->summary());
    }
    public function task(Request $request)
    {
        $data = $request->validate(['task_id' => 'required|uuid']);
        $task = UpdateTask::findOrFail($data['task_id']);
        return $this->success($task->summary());
    }
}
