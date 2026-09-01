<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class XboardStagingBootstrap extends Command
{
    protected $signature = 'xboard:staging-bootstrap';

    protected $description = 'Create the disposable staging server configuration from environment variables';

    public function handle(): int
    {
        if (getenv('STAGING_BOOTSTRAP_CONFIRM') !== '1') {
            $this->error('Set STAGING_BOOTSTRAP_CONFIRM=1 to run this staging-only command.');
            return self::FAILURE;
        }

        try {
            $serverToken = $this->requiredSecret('SERVER_TOKEN');
            if (strlen($serverToken) < 16) {
                throw new RuntimeException('SERVER_TOKEN must contain at least 16 characters.');
            }

            $panelUrl = $this->requiredEnv('STAGING_PANEL_URL');
            if (filter_var($panelUrl, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('STAGING_PANEL_URL must be an absolute URL.');
            }

            $nodeName = getenv('STAGING_NODE_NAME') ?: 'US2 Staging';
            $nodeHost = $this->requiredEnv('STAGING_NODE_HOST');
            if (filter_var($nodeHost, FILTER_VALIDATE_IP) !== false || !$this->isHostname($nodeHost)) {
                throw new RuntimeException('STAGING_NODE_HOST must be a DNS hostname for the TLS staging entrypoint.');
            }
            $publicPort = $this->portEnv('STAGING_NODE_PUBLIC_PORT', 443);
            $listenPort = $this->portEnv('STAGING_NODE_LISTEN_PORT', 30080);
            $expectedNodeId = $this->positiveIntEnv('STAGING_NODE_ID', 1);
            $websocketPath = $this->websocketPathEnv('STAGING_NODE_WS_PATH', '/xboard-staging-ws');

            admin_setting([
                'app_url' => rtrim($panelUrl, '/'),
                'server_token' => $serverToken,
                'server_ws_enable' => 1,
                'server_pull_interval' => 10,
                'server_push_interval' => 10,
            ]);

            $server = Server::updateOrCreate(
                ['name' => $nodeName],
                [
                    'type' => Server::TYPE_VLESS,
                    'host' => $nodeHost,
                    'port' => $publicPort,
                    'server_port' => $listenPort,
                    'rate' => 1,
                    'show' => 1,
                    'enabled' => true,
                    'sort' => 1,
                    'group_ids' => [],
                    'route_ids' => [],
                    'tags' => ['staging'],
                    'protocol_settings' => [
                        'tls' => 1,
                        'server_tls' => 0,
                        'network' => 'ws',
                        'network_settings' => [
                            'path' => $websocketPath,
                            'headers' => ['Host' => $nodeHost],
                        ],
                        'tls_settings' => [
                            'server_name' => $nodeHost,
                            'allow_insecure' => false,
                        ],
                        'flow' => null,
                    ],
                    'transfer_enable' => 0,
                ]
            );

            if ((int) $server->id !== $expectedNodeId) {
                throw new RuntimeException(
                    "Staging node ID mismatch: expected {$expectedNodeId}, got {$server->id}. " .
                    'The staging database must be empty before bootstrap.'
                );
            }

            Cache::flush();

            $this->info(sprintf(
                'Staging bootstrap complete: node_id=%d name=%s type=%s host=%s public_port=%d listen_port=%d ws_path=%s',
                $server->id,
                $server->name,
                $server->type,
                $server->host,
                $server->port,
                $server->server_port,
                $websocketPath,
            ));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function requiredSecret(string $name): string
    {
        $file = getenv($name . '_FILE', false);
        if ($file !== false && $file !== '') {
            if (!is_readable($file)) {
                throw new RuntimeException("Secret file for {$name} is not readable.");
            }

            $value = file_get_contents($file);
            if ($value === false) {
                throw new RuntimeException("Secret file for {$name} could not be read.");
            }

            $value = rtrim($value, "\r\n");
        } else {
            $value = getenv($name, false);
        }

        if ($value === false || $value === '') {
            throw new RuntimeException("{$name} or {$name}_FILE is required.");
        }

        return $value;
    }

    private function requiredEnv(string $name): string
    {
        $value = getenv($name, false);
        if ($value === false || trim($value) === '') {
            throw new RuntimeException("{$name} is required.");
        }

        return trim($value);
    }

    private function portEnv(string $name, int $default): int
    {
        $value = getenv($name, false);
        $port = $value === false || $value === '' ? $default : filter_var($value, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw new RuntimeException("{$name} must be an integer between 1 and 65535.");
        }

        return (int) $port;
    }

    private function positiveIntEnv(string $name, int $default): int
    {
        $value = getenv($name, false);
        $number = $value === false || $value === '' ? $default : filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < 1) {
            throw new RuntimeException("{$name} must be a positive integer.");
        }

        return (int) $number;
    }

    private function websocketPathEnv(string $name, string $default): string
    {
        $value = getenv($name, false);
        $path = $value === false || $value === '' ? $default : trim($value);
        if (!preg_match('#^/[A-Za-z0-9._~/-]+$#', $path) || str_contains($path, '..')) {
            throw new RuntimeException("{$name} must be a safe absolute URL path.");
        }

        return $path;
    }

    private function isHostname(string $value): bool
    {
        return strlen($value) <= 253
            && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && str_contains($value, '.');
    }
}
