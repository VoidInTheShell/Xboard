<?php

namespace Tests\Feature\Updates;

use App\Models\ServerMachine;
use App\Models\UpdateExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PanelCertificateExecutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_executor_fetches_desired_state(): void
    {
        $secret = Str::random(48);
        $this->executor('panel', 'panel', $secret);

        $panelCertId = $this->createPanelCertificate('acme-http');
        $this->createPanelCertificate('path-only');
        $contentCertId = $this->createPanelCertificate('content');

        $response = $this->getJson('/api/v2/update-executor/panel-certificates', $this->auth($secret))
            ->assertOk()
            ->assertJsonCount(3, 'data.certificates')
            ->assertJsonPath('data.certificates.0.id', $panelCertId)
            ->assertJsonPath('data.certificates.0.domains.0', 'panel.example.test')
            ->assertJsonPath('data.certificates.0.source_type', 'acme_http')
            ->assertJsonPath('data.certificates.0.revision', 1)
            ->assertJsonPath('data.certificates.0.status', 'pending');

        $path = collect($response->json('data.certificates'))->firstWhere('source_type', 'path');
        $this->assertSame('/etc/ssl/panel/fullchain.pem', $path['certificate_path']);
        $this->assertSame('/etc/ssl/panel/privkey.pem', $path['private_key_path']);
        $this->assertArrayNotHasKey('certificate_content', $path);

        $content = collect($response->json('data.certificates'))->firstWhere('source_type', 'content');
        $this->assertSame($contentCertId, $content['id']);
        $this->assertSame("-----BEGIN CERTIFICATE-----\npanel\n-----END CERTIFICATE-----\n", $content['certificate_content']);
        $this->assertSame("-----BEGIN PRIVATE KEY-----\npanel\n-----END PRIVATE KEY-----\n", $content['private_key_content']);
        $this->assertArrayNotHasKey('certificate_path', $content);
    }

    public function test_node_executor_cannot_access_panel_certificates(): void
    {
        $machine = ServerMachine::create([
            'name' => 'node machine',
            'token' => Str::random(32),
            'is_active' => true,
        ]);
        $secret = Str::random(48);
        $this->executor('node', 'machine:' . $machine->id, $secret, $machine->id);

        $this->getJson('/api/v2/update-executor/panel-certificates', $this->auth($secret))
            ->assertStatus(403);

        $this->postJson('/api/v2/update-executor/panel-certificate-report', [
            'certificates' => [],
        ], $this->auth($secret))->assertStatus(403);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v2/update-executor/panel-certificates')->assertStatus(401);
        $this->postJson('/api/v2/update-executor/panel-certificate-report', ['certificates' => []])
            ->assertStatus(401);
    }

    public function test_report_applies_status_and_material_metadata(): void
    {
        $secret = Str::random(48);
        $this->executor('panel', 'panel', $secret);
        $certificateId = $this->createPanelCertificate('acme-http');

        $expires = now()->addDays(60)->toISOString();
        $this->postJson('/api/v2/update-executor/panel-certificate-report', [
            'certificates' => [[
                'id' => $certificateId,
                'status' => 'valid',
                'applied_revision' => 1,
                'not_before_at' => now()->toISOString(),
                'expires_at' => $expires,
                'fingerprint' => 'SHA256:ABCDEF',
            ]],
        ], $this->auth($secret))
            ->assertOk()
            ->assertJsonPath('data.applied.0', $certificateId)
            ->assertJsonPath('data.stale', [])
            ->assertJsonPath('data.unknown', []);

        $this->assertDatabaseHas('v2_server_certificate', [
            'id' => $certificateId,
            'status' => 'valid',
            'fingerprint' => 'SHA256:ABCDEF',
        ]);
        $this->assertDatabaseMissing('v2_server_certificate', [
            'id' => $certificateId,
            'last_renewed_at' => null,
        ]);
    }

    public function test_stale_reports_are_ignored(): void
    {
        $secret = Str::random(48);
        $this->executor('panel', 'panel', $secret);
        $certificateId = $this->createPanelCertificate('acme-http');

        // The admin re-issued (revision 2) before the report for revision 1
        // arrived; the late report must not flip the issuing status.
        $this->app->make(\App\Services\Certificates\CertificateService::class)
            ->renewPanel($certificateId);

        $this->postJson('/api/v2/update-executor/panel-certificate-report', [
            'certificates' => [[
                'id' => $certificateId,
                'status' => 'valid',
                'applied_revision' => 1,
                'expires_at' => now()->addDays(60)->toISOString(),
            ]],
        ], $this->auth($secret))
            ->assertOk()
            ->assertJsonPath('data.stale.0', $certificateId);

        $this->assertDatabaseHas('v2_server_certificate', [
            'id' => $certificateId,
            'status' => 'issuing',
        ]);
    }

    public function test_report_lists_unknown_ids_and_never_touches_machine_scope(): void
    {
        $secret = Str::random(48);
        $this->executor('panel', 'panel', $secret);

        $machine = ServerMachine::create([
            'name' => 'machine',
            'token' => Str::random(32),
            'is_active' => true,
        ]);
        $machineCertId = (string) Str::uuid();
        $machineCert = new \App\Models\ServerCertificate();
        $machineCert->id = $machineCertId;
        $machineCert->fill([
            'machine_id' => $machine->id,
            'scope' => 'machine',
            'name' => 'machine cert',
            'source_type' => 'path',
            'domains' => ['node.example.test'],
            'auto_renew' => false,
            'revision' => 1,
            'status' => 'valid',
            'certificate_path' => '/etc/ssl/node/fullchain.pem',
            'private_key_path' => '/etc/ssl/node/privkey.pem',
        ]);
        $machineCert->save();

        $this->postJson('/api/v2/update-executor/panel-certificate-report', [
            'certificates' => [
                [
                    'id' => $machineCertId,
                    'status' => 'error',
                    'applied_revision' => 1,
                    'last_error' => 'must not apply',
                ],
                [
                    'id' => (string) Str::uuid(),
                    'status' => 'valid',
                    'applied_revision' => 1,
                ],
            ],
        ], $this->auth($secret))
            ->assertOk()
            ->assertJsonPath('data.applied', [])
            ->assertJsonCount(2, 'data.unknown');

        $this->assertDatabaseHas('v2_server_certificate', [
            'id' => $machineCertId,
            'status' => 'valid',
            'last_error' => null,
        ]);
    }

    private function executor(string $kind, string $scope, string $secret, ?int $machineId = null): UpdateExecutor
    {
        return UpdateExecutor::create([
            'id' => (string) Str::uuid(),
            'name' => $kind . '-executor',
            'kind' => $kind,
            'scope' => $scope,
            'machine_id' => $machineId,
            'secret_hash' => hash('sha256', $secret),
            'enabled' => true,
            'blocked' => false,
            'protocol' => 2,
            'state_schema' => 1,
            'updater_version' => 'v0.2.0',
            'last_seen_at' => now(),
        ]);
    }

    private function createPanelCertificate(string $variant): string
    {
        $service = $this->app->make(\App\Services\Certificates\CertificateService::class);
        if ($variant === 'content') {
            // Content sources are validated with real PEM parsing on save; the
            // executor projection only needs the stored values to flow through.
            $certificate = new \App\Models\ServerCertificate();
            $certificate->id = (string) Str::uuid();
            $certificate->fill([
                'scope' => 'panel',
                'machine_id' => null,
                'name' => 'panel content',
                'source_type' => 'content',
                'domains' => ['panel.example.test'],
                'auto_renew' => false,
                'revision' => 1,
                'status' => 'valid',
                'certificate_content' => "-----BEGIN CERTIFICATE-----\npanel\n-----END CERTIFICATE-----\n",
                'private_key_content' => "-----BEGIN PRIVATE KEY-----\npanel\n-----END PRIVATE KEY-----\n",
            ]);
            $certificate->save();
            return (string) $certificate->id;
        }
        $input = [
            'scope' => 'panel',
            'name' => 'panel ' . $variant,
            'domains' => ['panel.example.test'],
        ];
        if ($variant === 'acme-http') {
            $input['source_type'] = 'acme_http';
            $input['email'] = 'admin@example.test';
        } else {
            $input['source_type'] = 'path';
            $input['auto_renew'] = false;
            $input['certificate_path'] = '/etc/ssl/panel/fullchain.pem';
            $input['private_key_path'] = '/etc/ssl/panel/privkey.pem';
        }
        return (string) $service->savePanel($input)->id;
    }

    private function auth(string $secret): array
    {
        return ['Authorization' => 'Bearer ' . $secret];
    }
}
