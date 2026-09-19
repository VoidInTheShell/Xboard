<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Outbound;
use App\Models\Server;
use App\Models\ServerCertificate;
use App\Models\ServerGroup;
use App\Services\ServerService;
use App\Services\Certificates\CertificateService;
use App\Services\FallbackSiteService;
use App\Services\XrayConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers()->map(function ($item) {
            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'] ?? [])->get(['name', 'id']);
            $item['parent'] = $item->parent;
            if ($item->certificate_id && $item->certificate) {
                $item['certificate_ref_mode'] = $item->certificate_ref_mode ?: 'server_certificate';
                $item['cert_config'] = app(CertificateService::class)->toAdminLegacyConfig($item->certificate);
            } elseif (is_array($item->cert_config)) {
                $item['cert_config'] = app(CertificateService::class)->redactLegacyConfig($item->cert_config);
                $legacyMode = strtolower((string) ($item->cert_config['cert_mode'] ?? $item->cert_config['mode'] ?? ''));
                if ($legacyMode === 'file') {
                    $item['certificate_ref_mode'] = 'path';
                    $item['certificate_path'] = $item->cert_config['cert_file'] ?? null;
                    $item['private_key_path'] = $item->cert_config['key_file'] ?? null;
                }
            }
            return $item;
        });
        return $this->success($servers);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            try {
                DB::transaction(function () use ($server, &$params) {
                    $params = app(CertificateService::class)->prepareNodeParams($params, $server);
                    $this->validateServerCandidate($server, $params);
                    $server->update($params);
                    app(CertificateService::class)->syncBinding($server->fresh());
                });
                return $this->success(true);
            } catch (\Exception $e) {
                if ($e instanceof \App\Exceptions\ApiException) throw $e;
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        $candidate = new Server();
        $candidate->fill($params);
        if (!array_key_exists('fallback_site', $params)
            && app(FallbackSiteService::class)->supportsDefault($candidate)) {
            $params['fallback_site'] = FallbackSiteService::defaultConfig();
            $candidate->fallback_site = $params['fallback_site'];
        }
        if (XrayConfigService::supports($candidate)) {
            $params['xray_config'] = XrayConfigService::defaultNodeConfig();
            $params['default_outbound_tag'] = 'direct';
        }
        try {
            DB::transaction(function () use (&$params) {
                $params = app(CertificateService::class)->prepareNodeParams($params);
                $this->validateServerCandidate(null, $params);
                $server = Server::create($params);
                app(CertificateService::class)->syncBinding($server->fresh());
            });
            return $this->success(true);
        } catch (\Exception $e) {
            if ($e instanceof \App\Exceptions\ApiException) throw $e;
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }
    }

    /** Validate legacy server fields and certificate changes before writing. */
    private function validateServerCandidate(?Server $server, array $params): void
    {
        $candidate = $server ? clone $server : new Server();
        $candidate->fill($params);
        if ($server && $server->relationLoaded('machine')) {
            $candidate->setRelation('machine', $server->machine);
        }
        if (array_key_exists('machine_id', $params)) {
            // A machine reassignment must be evaluated against the proposed
            // relation rather than a relation copied from the old row.
            $candidate->unsetRelation('machine');
        }
        $this->validateCertificateMachine($candidate);
        XrayConfigService::preflightNode(
            $candidate,
            $candidate->xray_config instanceof \stdClass ? $candidate->xray_config : null,
            null,
            null,
        );
        app(FallbackSiteService::class)->validate($candidate);
    }

    /**
     * A certificate resource is machine-owned. Every write path, including
     * the small enabled/show/machine_id endpoint, must reject a node that
     * would keep a resource from another machine.
     */
    private function validateCertificateMachine(Server $candidate): void
    {
        if (!$candidate->certificate_id) {
            return;
        }
        if (!$candidate->machine_id || !ServerCertificate::query()
            ->whereKey($candidate->certificate_id)
            ->where('machine_id', $candidate->machine_id)
            ->exists()) {
            throw new ApiException('节点绑定的证书资源不属于目标服务器，请先选择该服务器的证书。', 422);
        }
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'show' => 'nullable|integer',
            'machine_id' => 'nullable|integer|exists:v2_server_machine,id',
            'enabled' => 'nullable|boolean',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        $willRun = array_key_exists('enabled', $params)
            ? (bool) $params['enabled']
            : (bool) $server->enabled;
        try {
            DB::transaction(function () use ($server, $params, $willRun): void {
                // This check intentionally runs even for disabled nodes: a
                // disabled row is still the source of the next machine
                // configuration and must never retain an invalid resource.
                if ($willRun || array_key_exists('machine_id', $params)) {
                    $this->validateServerCandidate($server, $params);
                } else {
                    $candidate = clone $server;
                    $candidate->fill($params);
                    $this->validateCertificateMachine($candidate);
                }

                if (array_key_exists('show', $params)) {
                    $server->show = (int) $params['show'];
                }
                if (array_key_exists('machine_id', $params)) {
                    $server->machine_id = $params['machine_id'] ?: null;
                }
                if (array_key_exists('enabled', $params)) {
                    $server->enabled = (bool) $params['enabled'];
                }

                if (!$server->save()) {
                    throw new ApiException('保存失败', 500);
                }
                app(CertificateService::class)->syncBinding($server->fresh());
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        $dependentOutbounds = Outbound::query()
            ->where('source_type', Outbound::SOURCE_NODE)
            ->where('source_node_id', $server->id)
            ->orderBy('id')
            ->pluck('name');
        if ($dependentOutbounds->isNotEmpty()) {
            XrayConfigService::failAt(
                'id',
                '该节点仍被出站引用：' . $dependentOutbounds->take(3)->implode('、') . '。请先修改或删除这些出站。',
                null,
            );
        }
        if ($server->delete() === false) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    /**
     * 批量删除节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要删除的节点']);
        }

        $dependentOutbounds = Outbound::query()
            ->where('source_type', Outbound::SOURCE_NODE)
            ->whereIn('source_node_id', $ids)
            ->orderBy('id')
            ->pluck('name');
        if ($dependentOutbounds->isNotEmpty()) {
            XrayConfigService::failAt(
                'ids',
                '所选节点仍被出站引用：' . $dependentOutbounds->take(3)->implode('、') . '。请先修改或删除这些出站。',
                null,
            );
        }

        try {
            $deleted = Server::whereIn('id', $ids)->delete();
            if ($deleted === false) {
                return $this->fail([500, '批量删除失败']);
            }
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量删除失败']);
        }
    }

    /**
     * 重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetTraffic(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        try {
            $server->u = 0;
            $server->d = 0;
            $server->save();
            
            Log::info("Server {$server->id} ({$server->name}) traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '重置失败']);
        }
    }

    /**
     * 批量重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchResetTraffic(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要重置的节点']);
        }

        try {
            Server::whereIn('id', $ids)->update([
                'u' => 0,
                'd' => 0,
            ]);
            
            Log::info("Servers " . implode(',', $ids) . " traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量重置失败']);
        }
    }

    /**
     * 批量更新节点属性（show等）
     */
    public function batchUpdate(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'show' => 'nullable|integer|in:0,1',
            'enabled' => 'nullable|boolean',
            'machine_id' => 'nullable|integer',
        ]);

        $ids = $params['ids'];
        if (empty($ids)) {
            return $this->fail([400, '请选择要更新的节点']);
        }

        $update = [];
        if (array_key_exists('show', $params) && $params['show'] !== null) {
            $update['show'] = (int) $params['show'];
        }
        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $update['enabled'] = (bool) $params['enabled'];
        }
        if (array_key_exists('machine_id', $params)) {
            $update['machine_id'] = $params['machine_id'] ?: null;
        }

        if (empty($update)) {
            return $this->fail([400, '没有可更新的字段']);
        }

        try {
            $servers = Server::whereIn('id', $ids)->get();
            DB::transaction(function () use ($servers, $update) {
                /** @var Server $server */
                foreach ($servers as $server) {
                    $candidate = clone $server;
                    $candidate->fill($update);
                    $this->validateCertificateMachine($candidate);
                    $server->update($update);
                    app(CertificateService::class)->syncBinding($server->fresh());
                }
            });
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量更新失败']);
        }
    }

    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        try {
            DB::transaction(function () use ($server): void {
                $copiedServer = $server->replicate();
                $copiedServer->show = 0;
                $copiedServer->code = null;
                $copiedServer->u = 0;
                $copiedServer->d = 0;
                $this->validateCertificateMachine($copiedServer);
                $copiedServer->save();
                app(CertificateService::class)->syncBinding($copiedServer->fresh());
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '复制失败']);
        }

        return $this->success(true);
    }

    /**
     * Generate ECH (Encrypted Client Hello) key pair.
     * Returns PEM-encoded ECH key (server-side) and ECH config (client-side).
     */
    public function generateEchKey(Request $request)
    {
        $publicName = $request->input('public_name', 'ech.example.com');
        if (strlen($publicName) < 1 || strlen($publicName) > 253) {
            throw new ApiException('public_name must be a valid domain (1-253 bytes)');
        }

        // Generate X25519 key pair
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // Build ECHConfigContents (draft-ietf-tls-esni-18)
        $contents = '';
        $contents .= pack('C', $configId);                // config_id
        $contents .= pack('n', 0x0020);                   // kem_id: DHKEM(X25519)
        $contents .= pack('n', 32) . $publicKey;          // public_key (length-prefixed)
        // cipher_suites: 2 suites × 4 bytes = 8 bytes
        $contents .= pack('n', 8);                        // cipher_suites byte length
        $contents .= pack('nn', 0x0001, 0x0001);          // HKDF-SHA256 + AES-128-GCM
        $contents .= pack('nn', 0x0001, 0x0003);          // HKDF-SHA256 + ChaCha20Poly1305
        $contents .= pack('C', 0);                        // max_name_length
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);                        // extensions: empty

        // ECHConfig = version(2) + length(2) + contents
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;

        // ECHConfigList = total_length(2) + configs
        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;

        // ECH Keys = private_key_len(2) + key(32) + config_len(2) + config
        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        $keyPem = "-----BEGIN ECH KEYS-----\n"
            . chunk_split(base64_encode($echKeysPayload), 64, "\n")
            . "-----END ECH KEYS-----";

        $configPem = "-----BEGIN ECH CONFIGS-----\n"
            . chunk_split(base64_encode($echConfigList), 64, "\n")
            . "-----END ECH CONFIGS-----";

        return $this->success([
            'key' => $keyPem,
            'config' => $configPem,
        ]);
    }
}
