<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Models\ServerCertificate;
use App\Services\Certificates\CertificateService;
use App\Services\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * machine controller
 */
class MachineController extends Controller
{
    /**
     * get nodes list for machine
     */
    public function nodes(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);

        $nodes = ServerService::getMachineNodes($machine)
            ->map(fn($node) => [
                'id' => $node->id,
                'type' => $node->type,
                'name' => $node->name,
            ])->values();

        $certificates = app(CertificateService::class)->listForMachine((int) $machine->id)
            ->map(fn ($certificate) => app(CertificateService::class)->toMachineProjection($certificate))
            ->values();

        return response()->json([
            'nodes' => $nodes,
            'certificates' => $certificates,
            'base_config' => [
                'push_interval' => (int) admin_setting('server_push_interval', 60),
                'pull_interval' => (int) admin_setting('server_pull_interval', 60),
            ],
        ]);
    }

    /**
     * report machine status
     */
    public function status(Request $request): JsonResponse
    {
        $request->validate([
            'cpu' => 'required|numeric|min:0|max:100',
            'mem.total' => 'required|integer|min:0',
            'mem.used' => 'required|integer|min:0',
            'swap.total' => 'nullable|integer|min:0',
            'swap.used' => 'nullable|integer|min:0',
            'disk.total' => 'nullable|integer|min:0',
            'disk.used' => 'nullable|integer|min:0',
            'net.in_speed' => 'nullable|numeric|min:0',
            'net.out_speed' => 'nullable|numeric|min:0',
            'certificates' => 'nullable|array',
            'certificates.*.id' => 'required_with:certificates|string|max:128',
            'certificates.*.revision' => 'required_with:certificates|integer|min:1',
            'certificates.*.applied_revision' => 'nullable|integer|min:0',
            'certificates.*.ready' => 'nullable|boolean',
            'certificates.*.state' => 'nullable|string|max:32',
            'certificates.*.error' => 'nullable|string|max:512',
            'certificates.*.not_before_at' => 'nullable|date',
            'certificates.*.expires_at' => 'nullable|date',
            'certificates.*.fingerprint' => 'nullable|string|max:128',
        ]);

        $machine = $this->authenticateMachine($request);
        $recordedAt = now()->timestamp;

        $loadStatus = [
            'cpu' => (float) $request->input('cpu'),
            'mem' => [
                'total' => (int) $request->input('mem.total'),
                'used' => (int) $request->input('mem.used'),
            ],
            'swap' => [
                'total' => (int) $request->input('swap.total', 0),
                'used' => (int) $request->input('swap.used', 0),
            ],
            'disk' => [
                'total' => (int) $request->input('disk.total', 0),
                'used' => (int) $request->input('disk.used', 0),
            ],
            'updated_at' => $recordedAt,
        ];

        $netInSpeed = $request->input('net.in_speed');
        $netOutSpeed = $request->input('net.out_speed');

        if ($netInSpeed !== null && $netOutSpeed !== null) {
            $loadStatus['net'] = [
                'in_speed' => (float) $netInSpeed,
                'out_speed' => (float) $netOutSpeed,
            ];
        }
        if (is_array($request->input('certificates'))) {
            $loadStatus['certificates'] = collect($request->input('certificates'))
                ->map(fn($certificate) => [
                    'id' => (string) ($certificate['id'] ?? ''),
                    'revision' => (int) ($certificate['revision'] ?? 0),
                    'applied_revision' => (int) ($certificate['applied_revision'] ?? 0),
                    'ready' => (bool) ($certificate['ready'] ?? false),
                    'state' => (string) ($certificate['state'] ?? 'unknown'),
                    'error' => $certificate['error'] ?? null,
                    'not_before_at' => $certificate['not_before_at'] ?? null,
                    'expires_at' => $certificate['expires_at'] ?? null,
                    'fingerprint' => $certificate['fingerprint'] ?? null,
                ])->values()->all();

            foreach ($request->input('certificates') as $reported) {
                $certificate = ServerCertificate::query()
                    ->whereKey((string) ($reported['id'] ?? ''))
                    ->where('machine_id', $machine->id)
                    ->first();
                if (!$certificate || (int) ($reported['revision'] ?? 0) !== (int) $certificate->revision) {
                    // A delayed heartbeat from an older desired revision must
                    // never overwrite the current status or expiry metadata.
                    continue;
                }
                $state = (string) ($reported['state'] ?? 'unknown');
                $status = match ($state) {
                    'ready' => 'valid',
                    'expiring' => 'expiring',
                    'expired' => 'expired',
                    'missing', 'error' => 'error',
                    default => $certificate->status ?: 'pending',
                };
                $certificate->forceFill([
                    'status' => $status,
                    'not_before_at' => $reported['not_before_at'] ?? null,
                    'expires_at' => $reported['expires_at'] ?? null,
                    'fingerprint' => $reported['fingerprint'] ?? null,
                    'last_error' => $status === 'error'
                        ? (string) ($reported['error'] ?? '机器未能加载当前证书材料。')
                        : null,
                ])->save();
            }
        }

        $machine->forceFill([
            'load_status' => $loadStatus,
            'last_seen_at' => $recordedAt,
        ])->save();

        $historyData = [
            'machine_id' => $machine->id,
            'cpu' => (float) $request->input('cpu'),
            'mem_total' => (int) $request->input('mem.total'),
            'mem_used' => (int) $request->input('mem.used'),
            'disk_total' => (int) $request->input('disk.total', 0),
            'disk_used' => (int) $request->input('disk.used', 0),
            'recorded_at' => $recordedAt,
        ];

        if ($netInSpeed !== null && $netOutSpeed !== null) {
            $historyData['net_in_speed'] = (float) $netInSpeed;
            $historyData['net_out_speed'] = (float) $netOutSpeed;
        }

        if (\App\Services\Logs\LogSettings::enabled('load') && \App\Services\Logs\LogBudget::accepts()) {
            ServerMachineLoadHistory::create($historyData);
        }

        return response()->json(['data' => true]);
    }

    private function authenticateMachine(Request $request): ServerMachine
    {
        $request->validate([
            'machine_id' => 'required|integer',
            'token' => 'required|string',
        ]);

        $machine = ServerMachine::where('id', $request->input('machine_id'))
            ->where('token', $request->input('token'))
            ->first();

        if (!$machine || !$machine->is_active) {
            abort(403, 'Machine not found or disabled');
        }

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();

        return $machine;
    }
}
