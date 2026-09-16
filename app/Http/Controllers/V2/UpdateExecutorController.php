<?php
namespace App\Http\Controllers\V2;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\UpdateExecutor;
use App\Models\ServerMachine;
use App\Services\Updates\UpdateManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateExecutorController extends Controller
{
    public function __construct(private readonly UpdateManager $updates) {}
    private function executor(Request $request): UpdateExecutor
    {
        $secret = $request->bearerToken();
        if (!$secret || strlen($secret) > 200) throw new ApiException('更新器认证失败。', 401);
        $executor = UpdateExecutor::where('secret_hash', hash('sha256', $secret))->where('enabled', true)->first();
        if (!$executor || ($executor->kind === 'node' && !ServerMachine::whereKey($executor->machine_id)->where('is_active', true)->exists())) {
            throw new ApiException('更新器认证失败。', 401);
        }
        return $executor;
    }
    public function heartbeat(Request $request)
    {
        $executor = $this->executor($request);
        $data = $request->validate(['architecture' => ['required', Rule::in(['linux/amd64', 'linux/arm64'])],
            'instances' => 'required|array|max:100', 'instances.*.id' => 'required|string|max:80|distinct|regex:/\\A[a-zA-Z0-9_.-]+\\z/',
            'instances.*.component' => 'required|string|max:32', 'instances.*.name' => 'required|string|max:100',
            'instances.*.version' => 'present|nullable|string|max:100',
            'instances.*.installation_method' => ['required', Rule::in(['docker', 'compose', 'systemd'])],
            'instances.*.ready' => 'required|boolean', 'instances.*.reason' => 'nullable|string|max:250',
            'instances.*.capabilities' => 'nullable|array',
            'instances.*.capabilities.panel_contract' => 'nullable|integer|in:1',
            'instances.*.capabilities.database_recovery' => 'nullable|boolean']);
        $this->updates->heartbeat($executor, $data);
        return $this->success(true);
    }
    public function claim(Request $request) { return $this->success($this->updates->claim($this->executor($request))); }
    public function report(Request $request)
    {
        $executor = $this->executor($request);
        $data = $request->validate(['task_id' => 'required|uuid', 'claim_token' => 'required|string|size:48',
            'sequence' => 'required|integer|min:1', 'status' => 'required|string|max:32',
            'message' => 'nullable|string|max:1000', 'result' => 'nullable|array',
            'result.version' => 'nullable|string|max:100']);
        return $this->success($this->updates->report($executor, $data));
    }
}
