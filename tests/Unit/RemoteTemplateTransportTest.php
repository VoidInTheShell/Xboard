<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\RemoteTemplateDownloader;
use PHPUnit\Framework\TestCase;

class RemoteTemplateTransportTest extends TestCase
{
    public function test_real_curl_pins_dns_enforces_body_limits_and_times_out(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $router = tempnam(sys_get_temp_dir(), 'template-http-');
        file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/large') { header('Content-Length: 2097153'); echo str_repeat('x', 2097153); }
elseif ($path === '/stream') { for ($i=0; $i<2050; $i++) { echo str_repeat('x', 1024); flush(); } }
elseif ($path === '/slow') { usleep(1500000); echo 'late'; }
else echo 'pinned transport works';
PHP);
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            $ready = false;
            for ($i=0; $i<100; $i++) {
                $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.05);
                if ($probe) { fclose($probe); $ready = true; break; }
                usleep(20000);
            }
            $this->assertTrue($ready);
            // Exercise cURL transport in isolation. Production download() still
            // forbids loopback targets; only this test exposes protected request().
            $transport = new class extends RemoteTemplateDownloader {
                public function fetch(int $port, string $path, float $timeout = 2): array
                {
                    return $this->request('http://pin-test.example.test:' . $port . $path, 'pin-test.example.test', $port, '127.0.0.1', $timeout);
                }
            };
            $this->assertSame('pinned transport works', $transport->fetch($port, '/ok')['body']);
            foreach (['/large', '/stream'] as $path) {
                try { $transport->fetch($port, $path); $this->fail('Oversize body accepted'); }
                catch (ApiException $e) { $this->assertStringContainsString('2 MiB', $e->getMessage()); }
            }
            $start = microtime(true);
            try { $transport->fetch($port, '/slow?token=hidden', 0.15); $this->fail('Timeout ignored'); }
            catch (ApiException $e) { $this->assertStringNotContainsString('hidden', $e->getMessage()); }
            $this->assertLessThan(1.25, microtime(true) - $start);
        } finally {
            proc_terminate($process);
            proc_close($process);
            unlink($router);
        }
    }
}
