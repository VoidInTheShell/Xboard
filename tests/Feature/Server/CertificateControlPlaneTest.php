<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\CertificateController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerCertificate;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Certificates\CertificateService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificateControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_path_certificate_is_owned_by_a_machine_and_cannot_be_deleted_while_bound(): void
    {
        Sanctum::actingAs($this->admin());
        $machine = ServerMachine::create([
            'name' => 'certificate machine',
            'token' => Str::random(32),
            'is_active' => true,
        ]);

        $savePath = $this->routePath(CertificateController::class . '@save');
        $record = $this->postJson($savePath, [
            'machine_id' => $machine->id,
            'name' => 'node path certificate',
            'source_type' => 'path',
            'domains' => ['node.example.test'],
            'auto_renew' => false,
            'certificate_path' => '/etc/ssl/xboard/fullchain.pem',
            'private_key_path' => '/etc/ssl/xboard/privkey.pem',
        ])->assertOk()->assertJsonPath('data.source_type', 'path');

        $certificateId = $record->json('data.id');
        $this->getJson($this->routePath(CertificateController::class . '@fetch') . '?machine_id=' . $machine->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $certificateId)
            ->assertJsonPath('data.0.dns_configured', false);

        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'bound node',
            'type' => 'vless',
            'host' => 'node.example.test',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'enabled' => false,
            'machine_id' => $machine->id,
            'protocol_settings' => [
                'tls' => 0,
                'network' => 'tcp',
                'tls_settings' => [],
            ],
        ]));

        $this->postJson($this->routePath(ManageController::class . '@save'), [
            'id' => $node->id,
            'type' => 'vless',
            'name' => $node->name,
            'host' => $node->host,
            'port' => $node->port,
            'server_port' => $node->server_port,
            'rate' => $node->rate,
            'enabled' => false,
            'machine_id' => $machine->id,
            'protocol_settings' => [
                'tls' => 0,
                'network' => 'tcp',
                'tls_settings' => [],
            ],
            'certificate_ref_mode' => 'server_certificate',
            'certificate_id' => $certificateId,
        ])->assertOk();

        $this->assertDatabaseHas('v2_server', [
            'id' => $node->id,
            'certificate_id' => $certificateId,
            'certificate_ref_mode' => 'server_certificate',
        ]);
        $this->assertDatabaseHas('v2_server_certificate_binding', [
            'certificate_id' => $certificateId,
            'server_id' => $node->id,
        ]);

        $this->postJson($this->routePath(CertificateController::class . '@drop'), [
            'id' => $certificateId,
            'machine_id' => $machine->id,
            'confirmation' => 'CONFIRM server.certificate.drop',
        ])->assertStatus(409);

        $this->postJson($this->routePath(ManageController::class . '@save'), [
            'id' => $node->id,
            'type' => 'vless',
            'name' => $node->name,
            'host' => $node->host,
            'port' => $node->port,
            'server_port' => $node->server_port,
            'rate' => $node->rate,
            'enabled' => false,
            'machine_id' => $machine->id,
            'protocol_settings' => [
                'tls' => 0,
                'network' => 'tcp',
                'tls_settings' => [],
            ],
            'certificate_ref_mode' => null,
            'certificate_id' => null,
        ])->assertOk();

        $this->postJson($this->routePath(CertificateController::class . '@drop'), [
            'id' => $certificateId,
            'machine_id' => $machine->id,
            'confirmation' => 'CONFIRM server.certificate.drop',
        ])->assertOk();

        $this->assertDatabaseMissing('v2_server_certificate', ['id' => $certificateId]);
        $this->assertDatabaseMissing('v2_server_certificate_binding', ['certificate_id' => $certificateId]);
    }

    public function test_legacy_certificate_config_remains_available_to_node_projection(): void
    {
        $machine = ServerMachine::create([
            'name' => 'legacy certificate machine',
            'token' => Str::random(32),
            'is_active' => true,
        ]);
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'legacy certificate node',
            'type' => 'vless',
            'host' => 'legacy.example.test',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'machine_id' => $machine->id,
            'cert_config' => [
                'cert_mode' => 'file',
                'cert_file' => '/etc/ssl/legacy/fullchain.pem',
                'key_file' => '/etc/ssl/legacy/privkey.pem',
            ],
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'tcp',
                'tls_settings' => [],
            ],
        ]));

        $config = ServerService::buildNodeConfig($node);
        $this->assertSame('file', $config['cert_config']['cert_mode']);
        $this->assertSame('/etc/ssl/legacy/fullchain.pem', $config['cert_config']['cert_file']);

        $certificate = new ServerCertificate();
        $certificate->id = (string) Str::uuid();
        $certificate->fill([
            'machine_id' => $machine->id,
            'name' => 'resource certificate',
            'source_type' => 'path',
            'domains' => ['resource.example.test'],
            'certificate_path' => '/etc/ssl/resource/fullchain.pem',
            'private_key_path' => '/etc/ssl/resource/privkey.pem',
            'status' => 'valid',
            'auto_renew' => false,
        ]);
        $certificate->save();
        $node->update([
            'certificate_id' => $certificate->id,
            'certificate_ref_mode' => 'server_certificate',
        ]);

        $resourceConfig = app(CertificateService::class)->toLegacyConfig($certificate);
        $this->assertSame('file', $resourceConfig['cert_mode']);
        $this->assertSame('/etc/ssl/resource/privkey.pem', $resourceConfig['key_file']);

        $resourceNodeConfig = ServerService::buildNodeConfig($node);
        $this->assertSame((string) $certificate->id, $resourceNodeConfig['certificate_id']);
        $this->assertSame('server_certificate', $resourceNodeConfig['certificate_ref_mode']);
    }

    private function admin(): User
    {
        return User::create([
            'email' => Str::uuid() . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'is_admin' => true,
            'is_staff' => false,
        ]);
    }

    private function routePath(string $action): string
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === $action) {
                return '/' . ltrim($route->uri(), '/');
            }
        }
        $this->fail("Route not found for {$action}");
    }
}
