<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\XrayConfigService;
use App\Services\VlessEncryptionService;
use App\Services\OutboundImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

class XrayController extends Controller
{
    public function generateVlessEncryption()
    {
        return $this->success(VlessEncryptionService::generate())
            ->header('Cache-Control', 'no-store, private');
    }

    private function objectInput(Request $request, string $field): stdClass
    {
        return XrayConfigService::object($this->payloadValue($request, $field), $field);
    }

    private function optionalObjectInput(Request $request, string $field): ?stdClass
    {
        if (!$request->has($field) || $request->input($field) === null) {
            return null;
        }
        return XrayConfigService::object($this->payloadValue($request, $field), $field);
    }

    /** Keep JSON object/array distinctions that Laravel's input bag flattens. */
    private function payloadValue(Request $request, string $field): mixed
    {
        try {
            $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            if ($body instanceof stdClass && property_exists($body, $field)) {
                return $body->$field;
            }
        } catch (\JsonException) {
            XrayConfigService::fail('Request body must be valid JSON');
        }
        return $request->input($field);
    }

    private function payloadHas(Request $request, string $field): bool
    {
        try {
            $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            return $body instanceof stdClass && property_exists($body, $field);
        } catch (\JsonException) {
            XrayConfigService::fail('Request body must be valid JSON');
        }
    }

    private function nodeId(Request $request): int
    {
        $value = $request->input('node_id', $request->input('server_id', $request->input('id')));
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            XrayConfigService::fail('node_id must be a positive integer');
        }
        return (int) $value;
    }

    public function fetch(Request $request)
    {
        $node = Server::query()->findOrFail($this->nodeId($request));
        return $this->success(XrayConfigService::snapshot($node));
    }

    /**
     * Read-only structural/runtime preflight for a node or machine default.
     * The service validates an unsaved model clone so this endpoint cannot
     * advance a revision or alter an existing client projection.
     */
    public function preflight(Request $request)
    {
        $hasNode = $request->input('node_id') !== null
            || $request->input('server_id') !== null
            || $request->input('id') !== null;
        $hasMachine = $this->payloadHas($request, 'machine_id') || $request->has('machine_id');

        if ($hasNode) {
            $params = $request->validate([
                'expected_revision' => 'nullable|integer|min:0',
            ]);
            $node = Server::query()->findOrFail($this->nodeId($request));
            if (array_key_exists('expected_revision', $params)
                && (int) $params['expected_revision'] !== (int) ($node->config_revision ?? 0)) {
                XrayConfigService::failAt(
                    'expected_revision',
                    '配置版本已变化，请重新加载后再检查。',
                );
            }
            $config = $this->payloadHas($request, 'xray_config') || $request->has('xray_config')
                ? $this->objectInput($request, 'xray_config')
                : null;
            $bindings = null;
            if ($this->payloadHas($request, 'outbound_bindings') || $request->has('outbound_bindings')) {
                $bindings = XrayConfigService::normalizeBindings($this->payloadValue($request, 'outbound_bindings'));
            }
            $clientSettings = $this->payloadHas($request, 'client_settings') || $request->has('client_settings')
                ? $this->optionalObjectInput($request, 'client_settings')
                : null;
            $certificateProvided = $this->payloadHas($request, 'cert_config') || $request->has('cert_config');
            $certificate = $certificateProvided ? $this->payloadValue($request, 'cert_config') : null;
            $result = XrayConfigService::preflightNode(
                $node,
                $config,
                $bindings,
                $clientSettings,
                $certificate,
                $certificateProvided,
            );

            return $this->success([
                'valid' => true,
                'node_id' => (int) $node->id,
                'config_revision' => $result['config_revision'],
                'config_hash' => $result['config_hash'],
            ]);
        }

        if ($hasMachine) {
            $params = $request->validate([
                'machine_id' => 'required|integer|exists:v2_server_machine,id',
            ]);
            $machine = ServerMachine::query()->findOrFail($params['machine_id']);
            $config = $this->objectInput($request, 'xray_config');
            $result = XrayConfigService::preflightMachine($machine, $config);
            return $this->success(['valid' => true, ...$result]);
        }

        XrayConfigService::failAt('node_id', '请提供节点或机器标识。');
    }

    public function save(Request $request)
    {
        $nodeId = $this->nodeId($request);
        $params = $request->validate([
            'expected_revision' => 'nullable|integer|min:0',
            'outbound_bindings' => 'sometimes|array',
        ]);
        $config = $this->objectInput($request, 'xray_config');
        $clientSettings = $this->optionalObjectInput($request, 'client_settings');
        $bindings = array_key_exists('outbound_bindings', $params)
            ? XrayConfigService::normalizeBindings($params['outbound_bindings'])
            : null;
        $certificateProvided = $this->payloadHas($request, 'cert_config') || $request->has('cert_config');
        $certificate = $certificateProvided
            ? XrayConfigService::validateCertificateConfig($this->payloadValue($request, 'cert_config'), 'cert_config')
            : null;

        $node = DB::transaction(function () use (
            $nodeId,
            $params,
            $config,
            $clientSettings,
            $bindings,
            $certificateProvided,
            $certificate,
        ) {
            $node = Server::query()->lockForUpdate()->findOrFail($nodeId);
            if (array_key_exists('expected_revision', $params)
                && (int) $params['expected_revision'] !== (int) ($node->config_revision ?? 0)) {
                XrayConfigService::fail('Configuration changed; reload the node before saving');
            }

            // Validate the proposed effective configuration on an unsaved
            // clone before mutating this locked model.  This keeps static
            // preflight and persistence on one contract and guarantees a
            // failed certificate/native check cannot leave a partial row.
            XrayConfigService::preflightNode(
                $node,
                $config,
                $bindings,
                $clientSettings,
                $certificate,
                $certificateProvided,
            );
            $previousEffective = XrayConfigService::effective($node);
            $node->xray_config = $config;
            if ($bindings !== null) {
                $node->outbound_bindings = $bindings;
            }
            if ($certificateProvided) {
                $node->cert_config = $certificate;
            }
            // Validate the complete merged value before writing the revision,
            // then atomically keep the public subscription projection aligned
            // with native VLESS flow/encryption settings.
            $effective = XrayConfigService::effective($node);
            XrayConfigService::synchronizeVlessRuntimeSettings(
                $node,
                $effective,
                $clientSettings,
                $previousEffective,
            );
            XrayConfigService::effective($node);
            $node->config_revision = (int) ($node->config_revision ?? 0) + 1;
            $node->save();
            return $node;
        });

        return $this->success(XrayConfigService::snapshot($node->fresh()));
    }

    public function bindings(Request $request)
    {
        $nodeId = $this->nodeId($request);
        if (!$request->isMethod('post')) {
            $node = Server::query()->findOrFail($nodeId);
            $snapshot = XrayConfigService::snapshot($node);
            return $this->success([
                'node_id' => $snapshot['node_id'],
                'config_revision' => $snapshot['config_revision'],
                'default_outbound_tag' => $snapshot['default_outbound_tag'],
                'outbound_bindings' => $snapshot['outbound_bindings'],
                'effective_config' => $snapshot['effective_config'],
            ]);
        }

        $params = $request->validate([
            'expected_revision' => 'nullable|integer|min:0',
            'outbound_bindings' => 'required|array',
        ]);
        $bindings = XrayConfigService::normalizeBindings($params['outbound_bindings']);
        $node = DB::transaction(function () use ($nodeId, $params, $bindings) {
            $node = Server::query()->lockForUpdate()->findOrFail($nodeId);
            if (array_key_exists('expected_revision', $params)
                && (int) $params['expected_revision'] !== (int) ($node->config_revision ?? 0)) {
                XrayConfigService::fail('Configuration changed; reload the node before saving');
            }
            $node->outbound_bindings = $bindings;
            XrayConfigService::effective($node);
            $node->config_revision = (int) ($node->config_revision ?? 0) + 1;
            $node->save();
            return $node;
        });
        return $this->success(XrayConfigService::snapshot($node->fresh()));
    }

    public function defaultOutbound(Request $request)
    {
        $nodeId = $this->nodeId($request);
        $params = $request->validate([
            'expected_revision' => 'nullable|integer|min:0',
            'default_outbound_tag' => 'required|string|max:255',
        ]);
        $tag = trim($params['default_outbound_tag']);
        if ($tag === '' || strtolower($tag) === 'api') {
            XrayConfigService::failAt('default_outbound_tag', '请选择可用的运行出站。', null);
        }

        $node = DB::transaction(function () use ($nodeId, $params, $tag) {
            $node = Server::query()->lockForUpdate()->findOrFail($nodeId);
            if (array_key_exists('expected_revision', $params)
                && (int) $params['expected_revision'] !== (int) ($node->config_revision ?? 0)) {
                XrayConfigService::failAt(
                    'expected_revision',
                    '配置版本已变化，请重新加载后再修改默认出站。',
                    null,
                );
            }
            if (($node->default_outbound_tag ?: 'direct') === $tag) {
                return $node;
            }
            $node->default_outbound_tag = $tag;
            XrayConfigService::effective($node);
            $node->config_revision = (int) ($node->config_revision ?? 0) + 1;
            $node->save();
            return $node;
        });

        return $this->success(XrayConfigService::snapshot($node->fresh()));
    }

    public function machine(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
        ]);
        $machine = ServerMachine::query()->findOrFail($params['machine_id']);

        if ($request->isMethod('post')) {
            $config = $this->objectInput($request, 'xray_config');
            DB::transaction(function () use ($machine, $config) {
                $machine = ServerMachine::query()->lockForUpdate()->findOrFail($machine->id);
                XrayConfigService::preflightMachine($machine, $config);
                $machine->xray_config = $config;
                $nodes = $machine->servers()->lockForUpdate()->get();
                foreach ($nodes as $node) {
                    $node->setRelation('machine', $machine);
                    XrayConfigService::effective($node);
                }
                $machine->save();
                foreach ($nodes as $node) {
                    $node->config_revision = (int) ($node->config_revision ?? 0) + 1;
                    $node->save();
                }
            });
            $machine = $machine->fresh();
        }

        return $this->success([
            'machine_id' => (int) $machine->id,
            'xray_config' => $machine->xray_config instanceof stdClass ? $machine->xray_config : new stdClass(),
        ]);
    }

    public function outbounds(Request $request)
    {
        $candidates = Outbound::query()->with('sourceNode')->orderBy('id')->get()
            ->map(fn (Outbound $candidate) => XrayConfigService::candidateSnapshot($candidate))
            ->values()->all();
        return $this->success($candidates);
    }

    public function importOutbounds(Request $request, OutboundImportService $importer)
    {
        $params = $request->validate([
            'source' => 'required|string|max:4194304',
        ]);
        return $this->success($importer->import($params['source']));
    }

    public function saveOutbound(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_outbound,id',
            'copy_from_id' => 'nullable|integer|exists:v2_outbound,id',
            'name' => 'required|string|max:255',
            'enabled' => 'sometimes|boolean',
            'source_type' => 'nullable|string|max:32',
            'source_node_id' => 'nullable|integer|exists:v2_server,id',
            'resolution_mode' => 'nullable|string|max:16',
            'service_credential' => 'nullable|array',
        ]);
        $input = $this->outboundInput($request);
        $candidate = DB::transaction(function () use ($params, $input) {
            if (!empty($params['id']) && !empty($params['copy_from_id'])) {
                XrayConfigService::fail('Choose an existing candidate to update or a source candidate to copy');
            }
            $candidate = empty($params['id'])
                ? new Outbound()
                : Outbound::query()->lockForUpdate()->findOrFail($params['id']);
            if (empty($params['id']) && !empty($params['copy_from_id'])) {
                $source = Outbound::query()->lockForUpdate()->findOrFail($params['copy_from_id']);
                // Copying is server-side so encrypted credentials never pass
                // through the browser and a redacted config cannot be saved.
                foreach (['source_type', 'source_node_id', 'resolution_mode', 'service_credential'] as $field) {
                    if (!array_key_exists($field, $input)) $input[$field] = $source->{$field};
                }
                if (($source->source_type ?: Outbound::SOURCE_MANUAL) === Outbound::SOURCE_MANUAL
                    && !array_key_exists('config', $input)) {
                    $input['config'] = $source->config;
                }
                if (($source->source_type ?: Outbound::SOURCE_MANUAL) === Outbound::SOURCE_NODE
                    && !array_key_exists('config', $input)
                    && !array_key_exists('config_patch', $input)
                    && $source->config_override instanceof stdClass) {
                    $input['config_patch'] = $source->config_override;
                }
            }
            $prepared = XrayConfigService::prepareCandidate($input, $candidate->exists ? $candidate : null);
            $candidate->fill([
                'name' => $params['name'],
                'enabled' => $params['enabled'] ?? ($candidate->exists ? $candidate->enabled : true),
                ...$prepared,
            ]);

            // Run the same in-memory candidate and bound-node validation used
            // by the read-only endpoint before any row is written.
            XrayConfigService::preflightOutbound(
                $candidate,
                $candidate->exists ? $this->boundNodes((int) $candidate->id) : collect(),
            );
            $candidate->save();

            foreach ($this->boundNodes($candidate->id) as $node) {
                XrayConfigService::effective($node);
                $node->config_revision = (int) ($node->config_revision ?? 0) + 1;
                $node->save();
            }
            return $candidate->fresh(['sourceNode']);
        });

        return $this->success(XrayConfigService::candidateSnapshot($candidate));
    }

    /** Read-only candidate preflight; no candidate or binding is persisted. */
    public function validateOutbound(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_outbound,id',
            'copy_from_id' => 'nullable|integer|exists:v2_outbound,id',
            'name' => 'required|string|max:255',
            'enabled' => 'sometimes|boolean',
            'source_type' => 'nullable|string|max:32',
            'source_node_id' => 'nullable|integer|exists:v2_server,id',
            'resolution_mode' => 'nullable|string|max:16',
            'service_credential' => 'nullable|array',
        ]);
        if (!empty($params['id']) && !empty($params['copy_from_id'])) {
            XrayConfigService::failAt('id', '更新和复制只能选择一种操作。', 'config');
        }

        $input = $this->outboundInput($request);
        $existing = !empty($params['id'])
            ? Outbound::query()->with('sourceNode')->findOrFail($params['id'])
            : null;
        if (empty($params['id']) && !empty($params['copy_from_id'])) {
            $source = Outbound::query()->findOrFail($params['copy_from_id']);
            foreach (['source_type', 'source_node_id', 'resolution_mode', 'service_credential'] as $field) {
                if (!array_key_exists($field, $input)) $input[$field] = $source->{$field};
            }
            if (($source->source_type ?: Outbound::SOURCE_MANUAL) === Outbound::SOURCE_MANUAL
                && !array_key_exists('config', $input)) {
                $input['config'] = $source->config;
            }
            if (($source->source_type ?: Outbound::SOURCE_MANUAL) === Outbound::SOURCE_NODE
                && !array_key_exists('config', $input)
                && !array_key_exists('config_patch', $input)
                && $source->config_override instanceof \stdClass) {
                $input['config_patch'] = $source->config_override;
            }
        }

        $candidate = XrayConfigService::candidateFromInput($input, $existing);
        $boundNodes = $existing ? $this->boundNodes($existing->id) : collect();
        $affected = XrayConfigService::preflightOutbound($candidate, $boundNodes);
        return $this->success([
            'valid' => true,
            'candidate' => XrayConfigService::candidateSnapshot($candidate),
            'bound_nodes' => $affected,
        ]);
    }

    /** Preserve native JSON objects while assembling outbound save input. */
    private function outboundInput(Request $request): array
    {
        $input = $request->all();
        foreach (['config', 'config_patch'] as $field) {
            if ($this->payloadHas($request, $field) || $request->has($field)) {
                $input[$field] = $this->payloadValue($request, $field);
            } else {
                unset($input[$field]);
            }
        }
        return $input;
    }

    public function snapshot(Request $request)
    {
        if (!$request->isMethod('post')) {
            $params = $request->validate(['id' => 'required|integer|exists:v2_outbound,id']);
            $candidate = Outbound::query()->with('sourceNode')->findOrFail($params['id']);
            return $this->success(XrayConfigService::candidateSnapshot($candidate));
        }

        $params = $request->validate([
            'source_node_id' => 'required|integer|exists:v2_server,id',
            'resolution_mode' => 'nullable|string|max:16',
            'service_credential' => 'required|array',
        ]);
        $source = Server::query()->findOrFail($params['source_node_id']);
        $hasConfig = $this->payloadHas($request, 'config') || $request->has('config');
        $hasConfigPatch = $this->payloadHas($request, 'config_patch') || $request->has('config_patch');
        if ($hasConfig && $hasConfigPatch) {
            XrayConfigService::fail('Use either config or config_patch for a source preview');
        }
        return $this->success(XrayConfigService::sourcePreview(
            $source,
            $params['service_credential'],
            $hasConfigPatch
                ? $this->optionalObjectInput($request, 'config_patch')
                : ($hasConfig ? $this->optionalObjectInput($request, 'config') : null),
            $params['resolution_mode'] ?? Outbound::RESOLUTION_PINNED,
            $hasConfig,
        ));
    }

    public function dropOutbound(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_outbound,id',
        ]);
        DB::transaction(function () use ($params) {
            $candidate = Outbound::query()->lockForUpdate()->findOrFail($params['id']);
            if ($this->boundNodes($candidate->id)->isNotEmpty()) {
                XrayConfigService::fail('Remove this outbound from its instances before deleting it');
            }
            $candidate->delete();
        });
        return $this->success(true);
    }

    private function boundNodes(int $id)
    {
        return Server::query()->whereNotNull('outbound_bindings')->get()->filter(
            fn (Server $node) => collect($node->outbound_bindings)->contains(
                fn (array $binding) => (int) ($binding['outbound_id'] ?? 0) === $id,
            ),
        );
    }
}
