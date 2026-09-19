<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeSyncService;
use App\Services\Updates\EnrollmentService;
use App\Models\UpdateExecutor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MachineController extends Controller
{
    private const INSTALL_VERSION_RULES = ['nullable', 'string', 'regex:/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-dev\.[1-9][0-9]*\.[1-9][0-9]*)?$/D'];
    private const INSTALL_VERSION_EXACT_RULES = ['required', 'string', 'regex:/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-dev\.[1-9][0-9]*\.[1-9][0-9]*)?$/D'];
    private const INSTALL_METHODS = ['systemd', 'docker', 'compose'];

    /**
     * 获取机器列表（附带关联节点数）
     */
    public function fetch(Request $request)
    {
        $machines = ServerMachine::withCount('servers')
            ->orderBy('id')
            ->get()
            ->map(function (ServerMachine $machine) {
                return [
                    'id' => $machine->id,
                    'name' => $machine->name,
                    'notes' => $machine->notes,
                    'is_active' => $machine->is_active,
                    'last_seen_at' => $machine->last_seen_at,
                    'load_status' => $machine->load_status,
                    'servers_count' => $machine->servers_count,
                    'created_at' => $machine->created_at,
                    'updated_at' => $machine->updated_at,
                    'updater' => $this->updaterSummary($machine),
                ];
            });

        return $this->success($machines);
    }

    /**
     * 创建 / 更新机器
     */
    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_server_machine,id',
            'name' => 'required|string|max:255',
            'version' => self::INSTALL_VERSION_RULES,
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($params['id'])) {
            $machine = ServerMachine::find($params['id']);
            $wasActive = (bool) $machine->is_active;
            $update = ['name' => $params['name']];
            if (array_key_exists('notes', $params)) {
                $update['notes'] = $params['notes'];
            }
            if (array_key_exists('is_active', $params)) {
                $update['is_active'] = $params['is_active'];
            }
            $machine->update($update);
            if (array_key_exists('is_active', $update)
                && $wasActive !== (bool) $machine->is_active) {
                // An active-state transition changes the machine's node set
                // from the Node process' point of view.  Publish even when no
                // node row changed: disabling sends an empty list, enabling
                // sends the current enabled node list for recovery.
                NodeSyncService::notifyMachineNodesChanged((int) $machine->id);
            }
            return $this->success(true);
        }

        $machine = ServerMachine::create([
            'name' => $params['name'],
            'notes' => $params['notes'] ?? null,
            'is_active' => $params['is_active'] ?? true,
            'token' => ServerMachine::generateToken(),
        ]);

        return $this->success([
            'id' => $machine->id,
            'enrollment_status' => 'not_issued',
        ]);
    }

    /**
     * 重置机器 Token
     */
    public function resetToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $token = ServerMachine::generateToken();
        $machine->update(['token' => $token]);

        return $this->success(['token' => $token]);
    }

    /**
     * 获取机器 Token（仅展示一次，用于首次配置）
     */
    public function getToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);

        return $this->success(['token' => $machine->token]);
    }

    /**
     * 获取机器模式一键安装命令
     */
    public function installCommand(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
            'version' => self::INSTALL_VERSION_EXACT_RULES,
            'mode' => ['required', Rule::in(self::INSTALL_METHODS)],
        ]);

        $machine = ServerMachine::find($params['id']);
        if (!$machine->is_active) {
            throw new ApiException('请先启用服务器后再生成安装命令。', 409);
        }

        return $this->success([
            'command' => $this->buildInstallCommand($request, $machine, $params),
            'enrollment_status' => 'issued',
            'expires_in_minutes' => EnrollmentService::TTL_MINUTES,
        ]);
    }

    /**
     * 删除机器（自动解除关联节点）
     */
    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $machineId = $machine->id;

        // Detach nodes first (sets machine_id = null), then delete and notify
        Server::where('machine_id', $machineId)->update(['machine_id' => null]);
        $machine->delete();

        // Notify with empty node list so WS process cleans up registry
        NodeSyncService::notifyMachineNodesChanged($machineId);

        return $this->success(true);
    }

    /**
     * 获取机器下的节点列表
     */
    public function nodes(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $nodes = Server::where('machine_id', $params['machine_id'])
            ->orderBy('sort')
            ->get(['id', 'name', 'type', 'host', 'port', 'show', 'enabled', 'sort']);

        return $this->success($nodes);
    }

    /**
     * 获取机器负载历史
     */
    public function history(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'limit' => 'nullable|integer|min:10|max:1440',
            'range_hours' => 'nullable|integer|min:1|max:24',
        ]);

        $query = ServerMachineLoadHistory::query()
            ->where('machine_id', $params['machine_id']);

        if (!empty($params['range_hours'])) {
            $query->where('recorded_at', '>=', now()->subHours((int) $params['range_hours'])->timestamp);
        }

        $limit = (int) ($params['limit'] ?? 60);

        $history = $query
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get([
                'cpu',
                'mem_total',
                'mem_used',
                'disk_total',
                'disk_used',
                'net_in_speed',
                'net_out_speed',
                'recorded_at',
            ])
            ->reverse()
            ->values();

        return $this->success($history);
    }

    private function buildInstallCommand(Request $request, ServerMachine $machine, array $params): string
    {
        $panelUrl = rtrim((string) (admin_setting('app_url') ?: $request->getSchemeAndHttpHost()), '/');
        $enrollmentService = app(EnrollmentService::class);
        // Reject a public HTTP panel before issuing the one-time token. The
        // installer enforces the same contract, but must not be handed a token
        // that can never reach its exchange endpoint.
        $panelUrl = $enrollmentService->validatePanelUrl($panelUrl);
        $version = $params['version'];
        $executor = UpdateExecutor::query()
            ->where('kind', 'panel')
            ->where('enabled', true)
            ->whereNotNull('updater_version')
            ->latest('last_seen_at')
            ->first();
        if (!$executor || !$executor->online() || !$executor->protocolReady()) {
            throw new ApiException('当前面板 Updater 尚未以兼容协议在线，请先完成 Updater 接入。', 409);
        }
        $updaterVersion = $executor->updater_version;
        $enrollment = $enrollmentService->issue(
            $machine,
            $request->user()?->id ? (int) $request->user()->id : null,
            $panelUrl
        );
        $installerUrl = 'https://github.com/VoidInTheShell/Xboard-Node/releases/download/' . $version . '/install.sh';

        return sprintf(
            'curl -fsSL %s | sudo bash -s -- --mode machine --panel %s --machine-id %d --enrollment-token %s --version %s --updater-version %s --installation-method %s',
            $installerUrl,
            escapeshellarg($panelUrl),
            $machine->id,
            escapeshellarg($enrollment['token']),
            escapeshellarg($version),
            escapeshellarg($updaterVersion),
            escapeshellarg($params['mode'])
        );
    }

    private function updaterSummary(ServerMachine $machine): ?array
    {
        $executor = UpdateExecutor::query()
            ->where('kind', 'node')
            ->where('machine_id', $machine->id)
            ->latest('last_seen_at')
            ->first();
        if (!$executor) return null;

        $online = $executor->online();
        return [
            'online' => $online,
            'ready' => $online && $executor->protocolReady() && !$executor->blocked,
            'blocked' => (bool) $executor->blocked,
            'protocol' => (int) $executor->protocol,
            'state_schema' => (int) $executor->state_schema,
            'updater_version' => $executor->updater_version,
            'installation_method' => $executor->installation_method,
            'architecture' => $executor->architecture,
            'handoff_phase' => $executor->handoff_phase,
            'last_seen_at' => $executor->last_seen_at,
        ];
    }
}
