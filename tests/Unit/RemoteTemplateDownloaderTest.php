<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\RemoteTemplateDownloader;
use PHPUnit\Framework\TestCase;

class RemoteTemplateDownloaderTest extends TestCase
{
    public function test_local_and_reserved_targets_never_reach_the_transport(): void
    {
        foreach (['127.0.0.1', '10.0.0.1', '100.100.100.200', '169.254.169.254', '192.168.1.1', '224.0.0.1', '::1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', '2002:7f00:1::', '2001:db8::1'] as $ip) {
            $downloader = new ProbeDownloader([$ip]);
            try { $downloader->download('https://public.example.test/config'); $this->fail('Blocked target accepted'); }
            catch (ApiException $e) { $this->assertSame(422, $e->getCode()); }
            $this->assertSame([], $downloader->calls);
        }
        $this->assertTrue((new RemoteTemplateDownloader())->isPublicAddress('1.1.1.1'));
        $this->assertTrue((new RemoteTemplateDownloader())->isPublicAddress('2606:4700:4700::1111'));
    }

    public function test_each_redirect_is_resolved_and_validated_again(): void
    {
        $downloader = new ProbeDownloader(['1.1.1.1']);
        $downloader->responses = [['status' => 302, 'location' => 'http://127.0.0.1/private', 'body' => '']];
        $this->expectException(ApiException::class);
        try { $downloader->download('http://public.example.test/config'); }
        finally { $this->assertCount(1, $downloader->calls); }
    }

    public function test_successful_relative_redirect_uses_pinned_address(): void
    {
        $downloader = new ProbeDownloader(['1.1.1.1']);
        $downloader->responses = [['status' => 302, 'location' => '/next', 'body' => ''], ['status' => 200, 'location' => null, 'body' => 'template']];
        $this->assertSame('template', $downloader->download('https://public.example.test/start'));
        $this->assertSame(['https://public.example.test/next', 'public.example.test', 443, '1.1.1.1'], $downloader->calls[1]);
    }

    public function test_rejects_credentials_non_http_mixed_dns_and_https_downgrade(): void
    {
        foreach (['file:///etc/passwd', 'http://user:password@example.test/a', 'https://example.test/a#fragment', "https://example.test/\r\nX:1", 'http://example.test\\@127.0.0.1'] as $url) {
            try { (new RemoteTemplateDownloader())->validateUrl($url); $this->fail('Bad URL accepted'); }
            catch (ApiException $e) { $this->assertSame(422, $e->getCode()); }
        }
        $mixed = new ProbeDownloader(['1.1.1.1', '127.0.0.1']);
        try { $mixed->download('https://example.test/a'); $this->fail('Mixed DNS accepted'); }
        catch (ApiException) { $this->assertSame([], $mixed->calls); }
        $downloader = new ProbeDownloader(['1.1.1.1']);
        $downloader->responses = [['status' => 302, 'location' => 'http://example.test/a', 'body' => '']];
        $this->expectException(ApiException::class);
        $downloader->download('https://example.test/a');
    }

    public function test_http_errors_and_redirect_loops_do_not_leak_url_secrets(): void
    {
        foreach ([['status' => 503, 'location' => null, 'body' => 'error'], ['status' => 302, 'location' => '/again?token=secret-value', 'body' => '']] as $response) {
            $downloader = new ProbeDownloader(['1.1.1.1']);
            $downloader->responses = array_fill(0, 5, $response);
            try { $downloader->download('https://example.test/a?token=secret-value'); $this->fail('Failure accepted'); }
            catch (ApiException $e) { $this->assertStringNotContainsString('secret-value', $e->getMessage()); }
            $this->assertLessThanOrEqual(4, count($downloader->calls));
        }
    }
}

class ProbeDownloader extends RemoteTemplateDownloader
{
    public array $calls = [];
    public array $responses = [];
    public function __construct(private array $addresses) {}
    protected function resolveAddresses(string $host): array { return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->addresses; }
    protected function request(string $url, string $host, int $port, string $address, float $timeout): array
    {
        $this->calls[] = [$url, $host, $port, $address];
        return array_shift($this->responses) ?? ['status' => 200, 'location' => null, 'body' => 'template'];
    }
}
