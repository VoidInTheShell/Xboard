<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\CertificateController;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PanelCertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_certificate_crud_round_trip(): void
    {
        Sanctum::actingAs($this->admin());

        $savePath = $this->routePath(CertificateController::class . '@save');
        $record = $this->postJson($savePath, [
            'scope' => 'panel',
            'name' => 'panel entry certificate',
            'source_type' => 'acme_http',
            'domains' => ['panel.example.test'],
            'auto_renew' => true,
            'email' => 'admin@example.test',
        ])->assertOk();

        $record->assertJsonPath('data.scope', 'panel')
            ->assertJsonPath('data.machine_id', null)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.revision', 1);
        $certificateId = $record->json('data.id');

        $this->getJson($this->routePath(CertificateController::class . '@fetch') . '?scope=panel')
            ->assertOk()
            ->assertJsonPath('data.0.id', $certificateId)
            ->assertJsonPath('data.0.scope', 'panel')
            ->assertJsonPath('data.0.machine_id', null);

        $this->postJson($this->routePath(CertificateController::class . '@validateConfig'), [
            'scope' => 'panel',
            'id' => $certificateId,
            'name' => 'panel entry certificate',
            'source_type' => 'acme_http',
            'domains' => ['panel.example.test', 'www.panel.example.test'],
            'email' => 'admin@example.test',
        ])->assertOk()->assertJsonPath('data.valid', true);

        $this->postJson($savePath, [
            'scope' => 'panel',
            'id' => $certificateId,
            'name' => 'panel entry certificate',
            'source_type' => 'acme_http',
            'domains' => ['panel.example.test', 'www.panel.example.test'],
            'email' => 'admin@example.test',
        ])->assertOk()->assertJsonPath('data.revision', 2);

        $this->postJson($this->routePath(CertificateController::class . '@drop'), [
            'scope' => 'panel',
            'id' => $certificateId,
            'confirmation' => 'CONFIRM server.certificate.drop',
        ])->assertOk();

        $this->getJson($this->routePath(CertificateController::class . '@fetch') . '?scope=panel')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_panel_scope_rejects_machine_only_sources(): void
    {
        Sanctum::actingAs($this->admin());

        foreach (['acme_dns', 'self_signed'] as $source) {
            $this->postJson($this->routePath(CertificateController::class . '@save'), [
                'scope' => 'panel',
                'name' => 'invalid panel source',
                'source_type' => $source,
                'domains' => ['panel.example.test'],
                'email' => 'admin@example.test',
                'dns_provider' => 'cloudflare',
                'dns_credentials' => '{"CF_API_TOKEN":"token"}',
            ])->assertStatus(422);
        }
    }

    public function test_panel_and_machine_certificates_are_isolated(): void
    {
        Sanctum::actingAs($this->admin());

        $machine = ServerMachine::create([
            'name' => 'isolation machine',
            'token' => Str::random(32),
            'is_active' => true,
        ]);

        $machineCert = $this->postJson($this->routePath(CertificateController::class . '@save'), [
            'machine_id' => $machine->id,
            'name' => 'machine cert',
            'source_type' => 'path',
            'domains' => ['node.example.test'],
            'auto_renew' => false,
            'certificate_path' => '/etc/ssl/node/fullchain.pem',
            'private_key_path' => '/etc/ssl/node/privkey.pem',
        ])->assertOk()->json('data.id');

        $panelCert = $this->postJson($this->routePath(CertificateController::class . '@save'), [
            'scope' => 'panel',
            'name' => 'panel cert',
            'source_type' => 'acme_http',
            'domains' => ['panel.example.test'],
            'email' => 'admin@example.test',
        ])->assertOk()->json('data.id');

        // The panel list never leaks machine resources and vice versa.
        $this->getJson($this->routePath(CertificateController::class . '@fetch') . '?scope=panel')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $panelCert);
        $this->getJson($this->routePath(CertificateController::class . '@fetch') . '?machine_id=' . $machine->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $machineCert);

        // Machine-scoped operations cannot reach a panel resource.
        $this->postJson($this->routePath(CertificateController::class . '@renew'), [
            'id' => $panelCert,
            'machine_id' => $machine->id,
            'confirmation' => 'CONFIRM server.certificate.renew',
        ])->assertStatus(404);
        $this->postJson($this->routePath(CertificateController::class . '@drop'), [
            'id' => $panelCert,
            'machine_id' => $machine->id,
            'confirmation' => 'CONFIRM server.certificate.drop',
        ])->assertStatus(404);

        // Panel-scoped operations cannot reach a machine resource.
        $this->postJson($this->routePath(CertificateController::class . '@renew'), [
            'scope' => 'panel',
            'id' => $machineCert,
            'confirmation' => 'CONFIRM server.certificate.renew',
        ])->assertStatus(404);
        $this->postJson($this->routePath(CertificateController::class . '@drop'), [
            'scope' => 'panel',
            'id' => $machineCert,
            'confirmation' => 'CONFIRM server.certificate.drop',
        ])->assertStatus(404);

        // Both resources still exist.
        $this->assertDatabaseCount('v2_server_certificate', 2);
    }

    public function test_panel_renew_is_only_supported_for_acme_http(): void
    {
        Sanctum::actingAs($this->admin());

        $acmeId = $this->postJson($this->routePath(CertificateController::class . '@save'), [
            'scope' => 'panel',
            'name' => 'panel acme',
            'source_type' => 'acme_http',
            'domains' => ['panel.example.test'],
            'email' => 'admin@example.test',
        ])->assertOk()->json('data.id');

        $this->postJson($this->routePath(CertificateController::class . '@renew'), [
            'scope' => 'panel',
            'id' => $acmeId,
            'confirmation' => 'CONFIRM server.certificate.renew',
        ])->assertOk()
            ->assertJsonPath('data.status', 'issuing')
            ->assertJsonPath('data.revision', 2);

        $pathId = $this->postJson($this->routePath(CertificateController::class . '@save'), [
            'scope' => 'panel',
            'name' => 'panel path',
            'source_type' => 'path',
            'domains' => ['panel.example.test'],
            'auto_renew' => false,
            'certificate_path' => '/etc/ssl/panel/fullchain.pem',
            'private_key_path' => '/etc/ssl/panel/privkey.pem',
        ])->assertOk()->json('data.id');

        $this->postJson($this->routePath(CertificateController::class . '@renew'), [
            'scope' => 'panel',
            'id' => $pathId,
            'confirmation' => 'CONFIRM server.certificate.renew',
        ])->assertStatus(422);
    }

    public function test_panel_save_does_not_require_machine_id(): void
    {
        Sanctum::actingAs($this->admin());

        // A shared frontend form may still carry machine_id; it is ignored
        // for panel scope rather than failing validation.
        $this->postJson($this->routePath(CertificateController::class . '@save'), [
            'scope' => 'panel',
            'machine_id' => 999999,
            'name' => 'panel entry',
            'source_type' => 'acme_http',
            'domains' => ['panel.example.test'],
            'email' => 'admin@example.test',
        ])->assertOk()->assertJsonPath('data.machine_id', null);

        // Machine scope keeps requiring an existing machine.
        $this->postJson($this->routePath(CertificateController::class . '@save'), [
            'name' => 'orphan machine cert',
            'source_type' => 'acme_http',
            'domains' => ['node.example.test'],
            'email' => 'admin@example.test',
        ])->assertStatus(422);
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
