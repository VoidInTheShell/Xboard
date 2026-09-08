<?php

namespace App\Http\Controllers;

use App\Models\McpKey;
use App\Services\AdminOperationBridge;
use App\Services\AdminOperationCatalog;
use App\Services\ChangeEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class McpServerController extends Controller
{
    private const MODERN_VERSION = '2026-07-28';
    private const LEGACY_VERSIONS = ['2025-03-26', '2025-06-18', '2025-11-25'];
    private const MAX_BODY_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly AdminOperationCatalog $catalog,
        private readonly AdminOperationBridge $bridge,
        private readonly ChangeEventService $changes
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->protocolError(null, -32600, 'Request body exceeds 4 MiB.', 413);
        }

        $message = json_decode($raw, true);
        if (!is_array($message) || array_is_list($message)) {
            return $this->protocolError(null, -32700, 'Invalid JSON-RPC message.', 400);
        }

        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
            return $this->protocolError($id, -32600, 'Invalid JSON-RPC request.', 400);
        }

        $modern = $method === 'server/discover'
            || $request->header('MCP-Protocol-Version') === self::MODERN_VERSION
            || $this->protocolVersion($message) === self::MODERN_VERSION;
        if ($modern && ($validation = $this->validateModernEnvelope($request, $message)) !== null) {
            return $validation;
        }

        if (!array_key_exists('id', $message)) {
            return response()->json(null, 202);
        }

        try {
            $result = match ($method) {
                'server/discover' => $this->discoverResult(),
                'initialize' => $this->initializeResult($message),
                'ping' => [],
                'tools/list' => $this->toolsList($request, $modern),
                'tools/call' => $this->toolsCall($request, $message, $modern),
                default => null,
            };

            if ($result === null) {
                return $this->protocolError($id, -32601, 'Method not found.', $modern ? 404 : 200);
            }

            if ($modern) {
                $result = $this->modernResult($result);
            }

            return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
        } catch (Throwable $e) {
            report($e);
            return $this->protocolError($id, -32603, 'Internal MCP error.', 500);
        }
    }

    private function discoverResult(): array
    {
        return [
            'supportedVersions' => [self::MODERN_VERSION],
            'capabilities' => ['tools' => []],
            'instructions' => 'Use xboard_admin_catalog before calls. Read with xboard_admin_read. Mutations require the latest change version and dangerous operations require the exact confirmation phrase returned by the catalog.',
            'ttlMs' => 300000,
            'cacheScope' => 'private',
        ];
    }

    private function initializeResult(array $message): array
    {
        $requested = data_get($message, 'params.protocolVersion');
        $version = is_string($requested) && in_array($requested, self::LEGACY_VERSIONS, true)
            ? $requested
            : self::LEGACY_VERSIONS[array_key_last(self::LEGACY_VERSIONS)];

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => $this->serverInfo(),
            'instructions' => 'Call xboard_admin_catalog first and use the returned operation identifiers.',
        ];
    }

    private function toolsList(Request $request, bool $modern): array
    {
        $key = $this->key($request);
        $tools = [
            [
                'name' => 'xboard_admin_catalog',
                'title' => 'Xboard Admin Operation Catalog',
                'description' => 'List the live, permission-filtered Xboard administrator operations and the current change version.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string', 'enum' => ['infrastructure', 'accounts', 'finance', 'operations', 'system']],
                        'mode' => ['type' => 'string', 'enum' => ['all', 'read', 'write'], 'default' => 'all'],
                        'search' => ['type' => 'string'],
                        'cursor' => ['type' => 'integer', 'minimum' => 0],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'xboard_admin_read',
                'title' => 'Read Xboard Admin Resource',
                'description' => 'Execute one read-only operation from xboard_admin_catalog through the existing Xboard Admin controller and validation stack.',
                'inputSchema' => $this->callSchema(false),
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'xboard_change_version',
                'title' => 'Read Xboard Change Version',
                'description' => 'Read the monotonic management version and change events after a known version.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'since' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
        ];

        if ($key->scope !== 'read') {
            $tools[] = [
                'name' => 'xboard_admin_mutate',
                'title' => 'Modify Xboard Admin Resource',
                'description' => 'Execute one write operation through the existing Xboard Admin controller. Supply the latest change version. Dangerous operations also require the exact catalog confirmation phrase.',
                'inputSchema' => $this->callSchema(true),
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            ];
        }

        $result = ['tools' => $tools];
        if ($modern) {
            $result += ['ttlMs' => 300000, 'cacheScope' => 'private'];
        }
        return $result;
    }

    private function toolsCall(Request $request, array $message, bool $modern): array
    {
        $name = data_get($message, 'params.name');
        $arguments = data_get($message, 'params.arguments', []);
        if (!is_string($name) || !is_array($arguments)) {
            return $this->toolResult(['message' => 'Tool name and arguments are required.'], true, $modern);
        }

        try {
            $result = match ($name) {
                'xboard_admin_catalog' => $this->catalogTool($request, $arguments),
                'xboard_admin_read' => $this->callAdminTool($request, $arguments, true),
                'xboard_admin_mutate' => $this->callAdminTool($request, $arguments, false),
                'xboard_change_version' => $this->versionTool($arguments),
                default => throw new RuntimeException("Unknown tool: {$name}"),
            };

            $isError = isset($result['status']) && (int) $result['status'] >= 400;
            return $this->toolResult($result, $isError, $modern);
        } catch (Throwable $e) {
            return $this->toolResult([
                'message' => $e->getMessage(),
                'current_version' => $this->changes->currentVersion(),
            ], true, $modern);
        }
    }

    private function catalogTool(Request $request, array $arguments): array
    {
        $key = $this->key($request);
        $domain = is_string($arguments['domain'] ?? null) ? $arguments['domain'] : null;
        $mode = is_string($arguments['mode'] ?? null) ? $arguments['mode'] : 'all';
        $search = strtolower(trim((string) ($arguments['search'] ?? '')));
        $cursor = max(0, (int) ($arguments['cursor'] ?? 0));
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 50)));

        $operations = collect($this->catalog->operations())
            ->filter(fn(array $operation) => $this->catalog->visibleTo($key, $operation))
            ->when($domain, fn($items) => $items->where('domain', $domain))
            ->when($mode === 'read', fn($items) => $items->where('read_only', true))
            ->when($mode === 'write', fn($items) => $items->where('read_only', false))
            ->when($search !== '', fn($items) => $items->filter(fn(array $operation) => str_contains(strtolower(
                $operation['id'] . ' ' . $operation['path'] . ' ' . $operation['resource']
            ), $search)))
            ->values();
        $page = $operations->slice($cursor, $limit)->map(fn(array $operation) => $this->catalog->publicDescriptor($operation))->values();
        $next = $cursor + $page->count();

        return [
            'operations' => $page->all(),
            'total' => $operations->count(),
            'next_cursor' => $next < $operations->count() ? $next : null,
            'current_version' => $this->changes->currentVersion(),
        ];
    }

    private function callAdminTool(Request $request, array $arguments, bool $readOnly): array
    {
        $operationId = $arguments['operation'] ?? null;
        if (!is_string($operationId) || ($operation = $this->catalog->resolve($operationId)) === null) {
            throw new RuntimeException('Unknown Xboard administrator operation. Call xboard_admin_catalog first.');
        }
        if ((bool) $operation['read_only'] !== $readOnly) {
            throw new RuntimeException($readOnly ? 'Use xboard_admin_mutate for this write operation.' : 'Use xboard_admin_read for this read-only operation.');
        }

        $key = $this->key($request);
        if (!$this->catalog->visibleTo($key, $operation)) {
            throw new RuntimeException('This MCP key does not grant the operation domain or mutation access.');
        }
        if (!$readOnly && $key->scope === 'read') {
            throw new RuntimeException('Read-only MCP keys cannot call xboard_admin_mutate.');
        }
        if (!$readOnly && $operation['dangerous'] && ($arguments['confirmation'] ?? null) !== $operation['required_confirmation']) {
            throw new RuntimeException('Dangerous operation requires the exact confirmation phrase returned by xboard_admin_catalog.');
        }
        if (!$readOnly && !isset($arguments['expected_change_version'])) {
            throw new RuntimeException('expected_change_version is required for mutations.');
        }

        $parameters = is_array($arguments['parameters'] ?? null) ? $arguments['parameters'] : [];
        $pathParameters = is_array($arguments['path_parameters'] ?? null) ? $arguments['path_parameters'] : [];
        $files = is_array($arguments['files'] ?? null) ? $arguments['files'] : [];

        $result = $this->bridge->execute(
            $request,
            $key,
            $operation,
            $parameters,
            $pathParameters,
            $files,
            $readOnly ? null : (int) $arguments['expected_change_version']
        );
        $result['operation'] = $operation['id'];
        $result['change_version'] = $this->changes->currentVersion();
        return $result;
    }

    private function versionTool(array $arguments): array
    {
        $since = max(0, (int) ($arguments['since'] ?? 0));
        return [
            'version' => $this->changes->currentVersion(),
            'events' => $this->changes->eventsSince($since),
        ];
    }

    private function toolResult(array $data, bool $isError, bool $modern): array
    {
        $result = [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ]],
            'structuredContent' => $data,
            'isError' => $isError,
        ];
        if ($modern) {
            $result['resultType'] = 'complete';
        }
        return $result;
    }

    private function callSchema(bool $mutation): array
    {
        $properties = [
            'operation' => ['type' => 'string', 'description' => 'Exact operation id returned by xboard_admin_catalog.'],
            'parameters' => ['type' => 'object', 'additionalProperties' => true],
            'path_parameters' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer']]],
            'files' => [
                'type' => 'object',
                'description' => 'Optional upload fields. Each value contains name, mime_type and content_base64. Total decoded size is limited to 8 MiB.',
                'additionalProperties' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'mime_type' => ['type' => 'string'],
                        'content_base64' => ['type' => 'string'],
                    ],
                    'required' => ['name', 'content_base64'],
                    'additionalProperties' => false,
                ],
            ],
        ];
        $required = ['operation'];
        if ($mutation) {
            $properties['expected_change_version'] = ['type' => 'integer', 'minimum' => 0];
            $properties['confirmation'] = ['type' => 'string'];
            $required[] = 'expected_change_version';
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    private function validateModernEnvelope(Request $request, array $message): ?JsonResponse
    {
        $id = $message['id'] ?? null;
        $headerVersion = $request->header('MCP-Protocol-Version');
        $bodyVersion = $this->protocolVersion($message);
        if ($headerVersion !== self::MODERN_VERSION || $bodyVersion !== self::MODERN_VERSION) {
            return $this->protocolError($id, -32020, 'MCP-Protocol-Version header and request metadata must both equal 2026-07-28.', 400);
        }

        $method = $message['method'];
        if ($request->header('Mcp-Method') !== $method) {
            return $this->protocolError($id, -32020, 'Mcp-Method header does not match the JSON-RPC method.', 400);
        }
        if (in_array($method, ['tools/call', 'resources/read', 'prompts/get'], true)) {
            $headerName = $this->decodeHeaderValue((string) $request->header('Mcp-Name'));
            $bodyName = data_get($message, 'params.name') ?? data_get($message, 'params.uri');
            if (!is_string($bodyName) || $headerName !== $bodyName) {
                return $this->protocolError($id, -32020, 'Mcp-Name header does not match the JSON-RPC request.', 400);
            }
        }

        return null;
    }

    private function decodeHeaderValue(string $value): ?string
    {
        if (preg_match('/^=\?base64\?([A-Za-z0-9+\/=]+)\?=$/', $value, $matches)) {
            $decoded = base64_decode($matches[1], true);
            return $decoded === false ? null : $decoded;
        }
        return $value;
    }

    private function protocolVersion(array $message): mixed
    {
        $meta = data_get($message, 'params._meta');
        return is_array($meta)
            ? ($meta['io.modelcontextprotocol/protocolVersion'] ?? null)
            : null;
    }

    private function modernResult(array $result): array
    {
        $result['resultType'] ??= 'complete';
        $result['_meta']['io.modelcontextprotocol/serverInfo'] = $this->serverInfo();
        return $result;
    }

    private function serverInfo(): array
    {
        return ['name' => 'xboard-admin', 'version' => '1.0.0'];
    }

    private function key(Request $request): McpKey
    {
        $key = $request->attributes->get('mcp_key');
        if (!$key instanceof McpKey) {
            throw new RuntimeException('MCP authentication context is missing.');
        }
        return $key;
    }

    private function protocolError(mixed $id, int $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
