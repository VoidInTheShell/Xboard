<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Services\ServerService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ServerHysteriaMasqueradeTest extends TestCase
{
    public function test_hysteria2_proxy_masquerade_is_validated_cast_and_sent_to_node(): void
    {
        $payload = [
            'type' => Server::TYPE_HYSTERIA,
            'name' => 'HY2 test',
            'host' => 'cdn.example.com',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'protocol_settings' => [
                'version' => 2,
                'bandwidth' => ['up' => 100, 'down' => 200],
                'obfs' => ['open' => false],
                'tls' => ['server_name' => 'cdn.example.com'],
                'masquerade' => [
                    'type' => 'proxy',
                    'url' => 'https://www.example.com/',
                    'rewrite_host' => true,
                ],
            ],
        ];

        $request = ServerSave::create('/', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules());
        $this->assertFalse($validator->fails(), (string) $validator->errors());

        $server = new Server();
        foreach ($payload as $key => $value) {
            $server->{$key} = $value;
        }

        $this->assertSame($payload['protocol_settings']['masquerade'], $server->protocol_settings['masquerade']);
        $this->assertSame(
            $payload['protocol_settings']['masquerade'],
            ServerService::buildNodeConfig($server)['masquerade']
        );
    }

    public function test_hysteria2_masquerade_rejects_non_http_proxy_url(): void
    {
        $payload = [
            'type' => Server::TYPE_HYSTERIA,
            'name' => 'HY2 test',
            'host' => 'cdn.example.com',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'protocol_settings' => [
                'version' => 2,
                'masquerade' => [
                    'type' => 'proxy',
                    'url' => 'file:///srv/decoy',
                ],
            ],
        ];

        $request = ServerSave::create('/', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('protocol_settings.masquerade.url', $validator->errors()->toArray());
    }
}
