<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const XRAY_TYPES = ['vmess', 'vless', 'trojan', 'shadowsocks', 'socks', 'http', 'hysteria'];

    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->string('default_outbound_tag')->default('direct')->after('outbound_bindings');
        });

        Schema::create('v2_xray_rule_file', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->string('name');
            $table->string('source', 32)->default('remote');
            $table->text('url')->nullable();
            $table->boolean('auto_update')->default(true);
            $table->unsignedSmallInteger('update_interval_hours')->default(24);
            $table->boolean('built_in')->default(false);
            $table->boolean('read_only')->default(false);
            $table->unsignedBigInteger('download_revision')->default(0);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('file_updated_at')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'name']);
            $table->foreign('server_id')->references('id')->on('v2_server')->cascadeOnDelete();
        });

        // Existing Xray-capable nodes receive the same editable baseline as
        // newly created nodes. The three entries are ordinary native rules;
        // only api -> api is treated as panel-owned by the admin surface.
        DB::table('v2_server')
            ->whereIn('type', self::XRAY_TYPES)
            ->orderBy('id')
            ->chunkById(100, function ($nodes) {
                foreach ($nodes as $node) {
                    $config = json_decode($node->xray_config ?: '{}', true);
                    if (!is_array($config)) {
                        $config = [];
                    }
                    $routing = is_array($config['routing'] ?? null) ? $config['routing'] : [];
                    $rules = is_array($routing['rules'] ?? null) ? array_values($routing['rules']) : [];
                    $routing['rules'] = $this->mergeDefaultRules($rules);
                    $config['routing'] = $routing;

                    DB::table('v2_server')->where('id', $node->id)->update([
                        'xray_config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'default_outbound_tag' => 'direct',
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_xray_rule_file');
        Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn('default_outbound_tag'));
    }

    private function mergeDefaultRules(array $rules): array
    {
        $hasMainlandIp = false;
        $hasMainlandDomain = false;
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            $hasMainlandIp = $hasMainlandIp || (($rule['outboundTag'] ?? null) === 'block'
                && in_array('geoip:cn', (array) ($rule['ip'] ?? []), true));
            $domain = (array) ($rule['domain'] ?? []);
            $hasMainlandDomain = $hasMainlandDomain || (($rule['outboundTag'] ?? null) === 'block'
                && in_array('geosite:cn', $domain, true)
                && in_array('domain:googleapis.cn', $domain, true)
                && in_array('domain:google.cn', $domain, true)
                && in_array('geosite:google-play@cn', $domain, true)
                && in_array('domain:ping0.cc', $domain, true));
        }

        $rules = array_values(array_filter($rules, static fn ($rule) => !is_array($rule)
            || (($rule['outboundTag'] ?? null) !== 'api'
                || !in_array('api', (array) ($rule['inboundTag'] ?? []), true))));
        $defaults = [
            ['type' => 'field', 'inboundTag' => ['api'], 'outboundTag' => 'api', 'enabled' => true],
        ];
        if (!$hasMainlandIp) {
            $defaults[] = ['type' => 'field', 'ip' => ['geoip:cn'], 'outboundTag' => 'block', 'enabled' => true];
        }
        if (!$hasMainlandDomain) {
            $defaults[] = [
                'type' => 'field',
                'domain' => [
                    'geosite:cn',
                    'domain:googleapis.cn',
                    'domain:google.cn',
                    'geosite:google-play@cn',
                    'domain:ping0.cc',
                ],
                'outboundTag' => 'block',
                'enabled' => true,
            ];
        }
        return [...$defaults, ...$rules];
    }
};
