<?php

namespace App\Services;

use App\Exceptions\ApiException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Symfony\Component\HttpFoundation\IpUtils;

class RemoteTemplateDownloader
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20',
    ];

    public function download(string $url): string
    {
        $deadline = microtime(true) + 25;
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            [$host, $port, $scheme] = $this->validateUrl($url);
            $addresses = $this->resolveAddresses($host);
            if (!$addresses) throw new ApiException('远程模板域名解析失败。', 422);
            foreach ($addresses as $address) {
                if (!$this->isPublicAddress($address)) throw new ApiException('远程模板链接必须指向公网地址。', 422);
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) throw new ApiException('远程模板下载超时。', 422);
            $result = $this->request($url, $host, $port, $addresses[0], $remaining);
            if (in_array($result['status'], [301, 302, 303, 307, 308], true)) {
                if ($redirects === 3 || !$result['location']) throw new ApiException('远程模板重定向次数过多或缺少目标地址。', 422);
                try { $next = (string) UriResolver::resolve(new Uri($url), new Uri($result['location'])); }
                catch (\Throwable) { throw new ApiException('远程模板重定向地址无效。', 422); }
                [, , $nextScheme] = $this->validateUrl($next);
                if ($scheme === 'https' && $nextScheme !== 'https') throw new ApiException('远程模板不允许从 HTTPS 重定向到 HTTP。', 422);
                $url = $next;
                continue;
            }
            if ($result['status'] !== 200) throw new ApiException('远程模板下载失败（HTTP ' . $result['status'] . '）。', 422);
            return $result['body'];
        }
        throw new ApiException('远程模板下载失败。', 422);
    }

    public function validateUrl(string $url): array
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new ApiException('远程模板链接无效。', 422);
        try { $parts = parse_url($url); } catch (\Throwable) { $parts = false; }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (!$parts || !in_array($scheme, ['http', 'https'], true) || !$host
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host))) {
            throw new ApiException('请使用不含登录凭据和片段标识的 HTTP 或 HTTPS 模板链接。', 422);
        }
        return [$host, $parts['port'] ?? ($scheme === 'https' ? 443 : 80), $scheme];
    }

    protected function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        return array_values(array_unique(array_filter(array_map(fn($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: []))));
    }

    public function isPublicAddress(string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        if (str_contains($address, ':') && !IpUtils::checkIp($address, '2000::/3')) return false;
        return !IpUtils::checkIp($address, self::BLOCKED);
    }

    protected function request(string $url, string $host, int $port, string $address, float $timeout): array
    {
        $body = ''; $location = null; $oversize = false; $headerBytes = 0;
        $curl = curl_init($url);
        $pinned = str_contains($address, ':') ? '[' . $address . ']' : $address;
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => filter_var($host, FILTER_VALIDATE_IP) ? [] : ["{$host}:{$port}:{$pinned}"],
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, (int) ($timeout * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) ($timeout * 1000)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Xboard-RemoteTemplate/1',
            CURLOPT_HTTPHEADER => ['Accept: application/json, application/yaml, text/plain, */*', 'Accept-Encoding: identity'],
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$location, &$headerBytes, &$oversize) {
                $headerBytes += strlen($line);
                if ($headerBytes > 32768) return 0;
                if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                if (stripos($line, 'Content-Length:') === 0 && (int) trim(substr($line, 15)) > self::MAX_BYTES) { $oversize = true; return 0; }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$oversize) {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) { $oversize = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($oversize) throw new ApiException('远程模板不能超过 2 MiB。', 422);
            // Never expose cURL messages: they may contain signed URLs or query credentials.
            if ($ok === false) throw new ApiException('远程模板连接失败或下载超时，请检查链接。', 422);
            return compact('status', 'location', 'body');
        } finally { curl_close($curl); }
    }
}
