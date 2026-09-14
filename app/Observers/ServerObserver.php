<?php

namespace App\Observers;

use App\Models\Server;
use App\Models\Outbound;
use App\Services\NodeSyncService;

class ServerObserver
{
    public bool $afterCommit = true;

    /**
     * Fields which change the native node configuration or a published
     * endpoint.  The Xray admin endpoints advance config_revision explicitly;
     * the normal server editor does not, so the saving hook fills that gap.
     */
    private const RUNTIME_FIELDS = [
        'host',
        'port',
        'server_port',
        'protocol_settings',
        'type',
        'route_ids',
        'custom_outbounds',
        'custom_routes',
        'cert_config',
        'fallback_site',
        'xray_config',
        'outbound_bindings',
        'machine_id',
        'enabled',
    ];

    /**
     * Keep ordinary admin/node edits on the same monotonic revision stream as
     * native Xray saves.  An explicit revision assignment (the control API)
     * is respected so one write can never increment twice.
     */
    public function saving(Server $server): void
    {
        if (!$server->exists
            || !$server->isDirty(self::RUNTIME_FIELDS)
            || $server->isDirty('config_revision')) {
            return;
        }

        $server->config_revision = (int) ($server->getRawOriginal('config_revision') ?? 0) + 1;
    }

    public function created(Server $server): void
    {
        $this->notifyMachineNodesChanged($server->machine_id);
    }

    public function updated(Server $server): void
    {
        $runtimeChanged = $server->wasChanged(self::RUNTIME_FIELDS)
            || $server->wasChanged('config_revision');

        if ($server->wasChanged('group_ids')) {
            NodeSyncService::notifyFullSync($server->id);
        } elseif ($runtimeChanged) {
            NodeSyncService::notifyConfigUpdated($server->id);
        }

        if ($runtimeChanged) {
            $this->notifyLiveDependents($server);
        }

        if ($server->wasChanged(['machine_id', 'enabled'])) {
            $this->notifyMachineChange(
                $server->machine_id,
                $server->getOriginal('machine_id')
            );
        }
    }

    public function deleted(Server $server): void
    {
        $this->notifyMachineChange(null, $server->getOriginal('machine_id') ?: $server->machine_id);
    }

    private function notifyMachineChange(?int $newMachineId, ?int $oldMachineId): void
    {
        $notified = [];

        if ($newMachineId) {
            NodeSyncService::notifyMachineNodesChanged($newMachineId);
            $notified[] = $newMachineId;
        }

        if ($oldMachineId && !in_array($oldMachineId, $notified, true)) {
            NodeSyncService::notifyMachineNodesChanged($oldMachineId);
        }
    }

    private function notifyMachineNodesChanged(?int $machineId): void
    {
        if ($machineId) {
            NodeSyncService::notifyMachineNodesChanged($machineId);
        }
    }

    /**
     * A live outbound is generated from its source node at delivery time.
     * When that source changes, every bound target must receive a new desired
     * revision so the node applies the regenerated endpoint/transport.  Walk
     * the dependency graph explicitly instead of relying on nested model
     * events (the dependent revision writes are deliberately quiet), which
     * also gives us a cycle guard for legacy rows imported outside the API.
     */
    private function notifyLiveDependents(Server $source): void
    {
        $visited = [];
        $this->notifyLiveDependentsFrom($source, $visited);
    }

    /**
     * @param array<int, bool> $visited
     */
    private function notifyLiveDependentsFrom(Server $source, array &$visited): void
    {
        $sourceId = (int) $source->id;
        if ($sourceId < 1 || isset($visited[$sourceId])) {
            return;
        }
        $visited[$sourceId] = true;

        $candidateIds = Outbound::query()
            ->where('source_type', Outbound::SOURCE_NODE)
            ->where('resolution_mode', Outbound::RESOLUTION_LIVE)
            ->where('source_node_id', $sourceId)
            ->where('enabled', true)
            ->pluck('id');

        if ($candidateIds->isEmpty()) {
            return;
        }

        // JSON binding rows are ordered objects, so a portable JSON query is
        // not available across the project's SQLite/MySQL deployments.  The
        // candidate table is small relative to the node set; filter the
        // decoded ordered bindings in PHP and preserve their order.
        $targets = Server::query()
            ->whereNotNull('outbound_bindings')
            ->get();
        foreach ($targets as $target) {
            $bound = collect($target->outbound_bindings ?? [])
                ->contains(function ($binding) use ($candidateIds): bool {
                    if ($binding instanceof \stdClass) {
                        $binding = get_object_vars($binding);
                    }
                    if (!is_array($binding) || ($binding['enabled'] ?? true) === false) {
                        return false;
                    }
                    return $candidateIds->contains((int) ($binding['outbound_id'] ?? 0));
                });
            if (!$bound || (int) $target->id === $sourceId || isset($visited[(int) $target->id])) {
                continue;
            }

            $target->config_revision = (int) ($target->config_revision ?? 0) + 1;
            // The parent update event already completed its transaction.  A
            // quiet save avoids recursively firing this observer while the
            // explicit traversal below still handles downstream dependents.
            $target->saveQuietly();
            NodeSyncService::notifyConfigUpdated((int) $target->id);
            $this->notifyLiveDependentsFrom($target, $visited);
        }
    }
}
