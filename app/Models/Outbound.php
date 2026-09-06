<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Outbound extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_NODE = 'server';

    public const RESOLUTION_PINNED = 'pinned';
    public const RESOLUTION_LIVE = 'live';

    protected $table = 'v2_outbound';
    protected $guarded = ['id'];
    protected $casts = [
        'config' => 'object',
        'enabled' => 'boolean',
        'source_node_id' => 'integer',
        'resolution_mode' => 'string',
        'service_credential' => 'encrypted:array',
        'source_snapshot' => 'object',
        // For server-sourced candidates this is a sparse, non-identity patch
        // applied to a freshly generated source outbound.  It must remain
        // separate from config so live candidates never freeze source data.
        'config_override' => 'object',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    protected $hidden = ['service_credential'];

    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'source_node_id');
    }

    /**
     * Keep secret material out of every normal candidate response.  The
     * control plane can tell whether a credential exists without receiving
     * the credential itself; saving an omitted value keeps the current secret.
     */
    public function toControlPlaneArray(): array
    {
        $data = $this->toArray();
        $data['credential_configured'] = is_array($this->service_credential)
            && $this->service_credential !== [];
        $data['source_node'] = $this->relationLoaded('sourceNode') && $this->sourceNode
            ? ['id' => $this->sourceNode->id, 'name' => $this->sourceNode->name, 'type' => $this->sourceNode->type]
            : null;
        return $data;
    }
}
