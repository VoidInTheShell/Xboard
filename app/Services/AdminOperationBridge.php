<?php

namespace App\Services;

use App\Models\McpKey;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminOperationBridge
{
    private const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
    private const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly AdminOperationCatalog $catalog)
    {
    }

    public function execute(
        Request $parentRequest,
        McpKey $key,
        array $operation,
        array $parameters,
        array $pathParameters = [],
        array $files = [],
        ?int $expectedVersion = null
    ): array {
        if (!$this->catalog->visibleTo($key, $operation)) {
            throw new RuntimeException('该 MCP Key 没有执行此管理操作的权限。');
        }

        [$uploadedFiles, $temporaryPaths] = $this->buildUploadedFiles($files);
        $uri = '/api/v2/' . trim((string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        ), '/') . '/' . $this->fillPath($operation['path'], $pathParameters);

        $method = strtoupper((string) $operation['method']);
        $usesJsonBody = $uploadedFiles === [] && !in_array($method, ['GET', 'HEAD'], true);
        $server = [
            'REMOTE_ADDR' => $parentRequest->getClientIp() ?: '127.0.0.1',
            'HTTP_USER_AGENT' => $parentRequest->userAgent() ?: 'Xboard MCP',
            'HTTP_ACCEPT' => 'application/json, text/plain, application/octet-stream',
        ];
        $content = null;
        if ($usesJsonBody) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode(
                $parameters,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        }
        $subRequest = Request::create(
            $uri,
            $method,
            $usesJsonBody ? [] : $parameters,
            [],
            $uploadedFiles,
            $server,
            $content,
        );
        $subRequest->attributes->set('mcp_key', $key);
        $subRequest->attributes->set('mcp_admin', $key->owner);
        $subRequest->attributes->set('mcp_request_id', app(ChangeEventService::class)->requestId($parentRequest));
        if ($expectedVersion !== null) {
            $subRequest->attributes->set('mcp_expected_change_version', $expectedVersion);
        }
        $subRequest->setUserResolver(fn() => $key->owner);

        $originalRequest = app('request');
        app()->instance('request', $subRequest);

        try {
            $response = app('router')->dispatch($subRequest);
            return $this->normalizeResponse($response);
        } finally {
            app()->instance('request', $originalRequest);
            foreach ($temporaryPaths as $path) {
                @unlink($path);
            }
        }
    }

    private function fillPath(string $path, array $parameters): string
    {
        return preg_replace_callback('/\{([^}?]+)\??\}/', function (array $match) use ($parameters) {
            $name = $match[1];
            if (!array_key_exists($name, $parameters) || !is_scalar($parameters[$name])) {
                throw new RuntimeException("缺少路径参数：{$name}");
            }

            return rawurlencode((string) $parameters[$name]);
        }, $path) ?? $path;
    }

    private function buildUploadedFiles(array $files): array
    {
        $uploads = [];
        $paths = [];
        $total = 0;

        foreach ($files as $field => $definition) {
            if (!is_string($field) || !is_array($definition)) {
                throw new RuntimeException('文件参数必须是字段名到文件定义的对象。');
            }
            $encoded = $definition['content_base64'] ?? null;
            if (!is_string($encoded) || ($content = base64_decode($encoded, true)) === false) {
                throw new RuntimeException("文件 {$field} 缺少有效的 content_base64。");
            }
            $total += strlen($content);
            if ($total > self::MAX_UPLOAD_BYTES) {
                throw new RuntimeException('单次 MCP 调用的上传文件总大小不能超过 8 MiB。');
            }

            $directory = storage_path('app/mcp-uploads');
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('无法创建 MCP 临时上传目录。');
            }
            $path = tempnam($directory, 'upload-');
            if ($path === false || file_put_contents($path, $content, LOCK_EX) === false) {
                throw new RuntimeException("无法准备上传文件 {$field}。");
            }
            @chmod($path, 0600);
            $paths[] = $path;
            $name = basename((string) ($definition['name'] ?? $field . '.bin'));
            $mime = is_string($definition['mime_type'] ?? null) ? $definition['mime_type'] : null;
            $uploads[$field] = new UploadedFile($path, $name, $mime, null, true);
        }

        return [$uploads, $paths];
    }

    private function normalizeResponse(Response $response): array
    {
        $contentType = (string) $response->headers->get('content-type', 'application/octet-stream');
        $disposition = (string) $response->headers->get('content-disposition', '');

        if ($response instanceof BinaryFileResponse) {
            $path = $response->getFile()->getPathname();
            return $this->fileResult($response->getStatusCode(), $path, $contentType, $disposition);
        }

        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $content = (string) ob_get_clean();
        } else {
            $content = (string) $response->getContent();
        }

        if (strlen($content) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('管理接口响应超过 MCP 10 MiB 上限，请缩小查询范围。');
        }

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $body = $decoded;
            $encoding = 'json';
        } elseif ($this->isText($contentType)) {
            $body = $content;
            $encoding = 'text';
        } else {
            $body = base64_encode($content);
            $encoding = 'base64';
        }

        return [
            'status' => $response->getStatusCode(),
            'content_type' => $contentType,
            'content_disposition' => $disposition ?: null,
            'encoding' => $encoding,
            'body' => $body,
        ];
    }

    private function fileResult(int $status, string $path, string $contentType, string $disposition): array
    {
        $size = filesize($path);
        if ($size === false || $size > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('下载文件超过 MCP 10 MiB 上限。');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('无法读取管理接口返回的文件。');
        }

        return [
            'status' => $status,
            'content_type' => $contentType,
            'content_disposition' => $disposition ?: null,
            'encoding' => $this->isText($contentType) ? 'text' : 'base64',
            'body' => $this->isText($contentType) ? $content : base64_encode($content),
            'size' => $size,
            'sha256' => hash('sha256', $content),
        ];
    }

    private function isText(string $contentType): bool
    {
        return Str::startsWith(strtolower($contentType), ['text/', 'application/json', 'application/xml']);
    }
}
