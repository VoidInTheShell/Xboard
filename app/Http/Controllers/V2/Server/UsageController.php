<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Services\Usage\UsageIngestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class UsageController extends Controller
{
    public function node(Request $request)
    {
        $node = $request->attributes->get('node_info');
        abort_unless($node && $node->enabled, 403);
        return $this->accept($request, 'node', (int) $node->id, $node);
    }

    public function machine(Request $request)
    {
        $request->validate(['machine_id' => 'required|integer|min:1', 'token' => 'required|string|max:128']);
        $machine = ServerMachine::find($request->integer('machine_id'));
        abort_unless($machine && $machine->is_active && hash_equals($machine->token, (string) $request->input('token')), 403);
        return $this->accept($request, 'machine', (int) $machine->id);
    }

    private function accept(Request $request, string $scope, int $id, $node = null)
    {
        abort_unless(\App\Services\Usage\UsageSettings::get('enabled'), 503, 'Usage collection is disabled');
        abort_if(strlen($request->getContent()) > 1048576, 413, 'Report exceeds 1 MiB');
        $key = "usage:ingest:$scope:$id";
        abort_if(RateLimiter::tooManyAttempts($key, 12), 429, 'Report rate exceeded');
        RateLimiter::hit($key, 60);
        $max = config('usage.max_report_rows');
        $rules = [
            'epoch' => 'required|string|regex:/^[a-zA-Z0-9_-]{16,64}$/',
            'sequence' => 'required|integer|min:1|max:9007199254740991',
            'sampled_at' => 'required|integer|min:' . (time() - 300) . '|max:' . (time() + 30),
            'counters' => "present|array|max:$max",
            'counters.*.up' => 'required|integer|min:0|max:9007199254740991',
            'counters.*.down' => 'required|integer|min:0|max:9007199254740991',
        ];
        if ($scope === 'node') {
            $rules += [
                'core' => 'required|in:xray,sing-box',
                'counters.*.user_id' => 'required|integer|min:1|distinct',
                'devices' => "present|array|max:$max",
                'devices_complete' => 'sometimes|boolean',
                'devices.*.user_id' => 'required|integer|min:1',
                'devices.*.ip' => 'required|ip',
                'devices.*.online' => 'sometimes|boolean',
                'devices.*.first_seen' => 'sometimes|integer|min:1|max:' . time(),
                'devices.*.last_seen' => 'sometimes|integer|min:1|max:' . time(),
                'devices.*.generation' => 'nullable|string|regex:/^[a-f0-9]{32}$/',
                'devices.*.up' => 'nullable|integer|min:0|max:9007199254740991',
                'devices.*.down' => 'nullable|integer|min:0|max:9007199254740991',
                'devices.*.up_speed' => 'nullable|integer|min:0|max:1000000000000',
                'devices.*.down_speed' => 'nullable|integer|min:0|max:1000000000000',
            ];
        } else {
            $rules['counters'] = 'present|array|max:64';
            $rules['counters.*.interface'] = 'required|string|regex:/^[a-zA-Z0-9_.:-]{1,64}$/|distinct';
        }
        $data = $request->validate($rules);
        $accepted = app(UsageIngestService::class)->ingest($scope, $id, $data, $node);
        return response()->json(['data' => ['accepted' => $accepted]]);
    }
}
