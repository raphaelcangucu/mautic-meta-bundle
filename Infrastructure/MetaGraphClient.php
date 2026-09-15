<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Infrastructure;

use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionCredentialProvider;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MetaGraphClient implements MetaGraphClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ConnectionCredentialProvider $credentials,
        private ConnectionRateLimiter $rateLimiter,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function get(MetaConnection $connection, string $path, array $query = []): array
    {
        return $this->request($connection, 'GET', $path, ['query' => $query]);
    }

    public function post(MetaConnection $connection, string $path, array $payload): array
    {
        return $this->request($connection, 'POST', $path, ['json' => $payload]);
    }

    public function delete(MetaConnection $connection, string $path, array $query = []): array
    {
        return $this->request($connection, 'DELETE', $path, ['query' => $query]);
    }

    public function downloadWhatsAppMedia(MetaConnection $connection, string $mediaId, int $maximumBytes = 26214400): array
    {
        if (1 !== preg_match('/^[0-9]{5,40}$/', $mediaId) || $maximumBytes < 1) {
            throw new \InvalidArgumentException('A valid WhatsApp media id and size limit are required.');
        }

        $metadata = $this->get($connection, $mediaId);
        $url = trim((string) ($metadata['url'] ?? ''));
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ('https' !== ($parts['scheme'] ?? null)
            || (0 !== strcasecmp($host, 'lookaside.fbsbx.com') && !str_ends_with($host, '.fbcdn.net'))
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new \RuntimeException('Meta returned an invalid media download location.');
        }

        $declaredSize = max(0, (int) ($metadata['file_size'] ?? 0));
        if ($declaredSize > $maximumBytes) {
            throw new \RuntimeException('WhatsApp media exceeds the allowed preview size.');
        }

        $credentials = $this->credentials->for($connection);
        $this->rateLimiter->reserve($connection);
        $response = $this->httpClient->request('GET', $url, [
            'auth_bearer' => $credentials->accessToken,
            'headers' => ['Accept' => '*/*'],
            'timeout' => 30,
        ]);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException('Meta could not provide this WhatsApp media.');
        }
        $headers = $response->getHeaders(false);
        $contentLength = max(0, (int) ($headers['content-length'][0] ?? 0));
        if ($contentLength > $maximumBytes) {
            throw new \RuntimeException('WhatsApp media exceeds the allowed preview size.');
        }
        $contents = $response->getContent(false);
        if ('' === $contents || strlen($contents) > $maximumBytes) {
            throw new \RuntimeException('WhatsApp media is empty or exceeds the allowed preview size.');
        }
        $mimeType = strtolower(trim((string) ($headers['content-type'][0] ?? $metadata['mime_type'] ?? 'application/octet-stream')));
        $mimeType = trim(explode(';', $mimeType, 2)[0]);

        return ['contents' => $contents, 'mimeType' => $mimeType ?: 'application/octet-stream', 'fileSize' => strlen($contents)];
    }

    public function upload(MetaConnection $connection, string $fileName, string $contents, string $mimeType): string
    {
        if ('' === $contents || !in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            throw new \InvalidArgumentException('A non-empty JPEG or PNG file is required.');
        }

        $credentials = $this->credentials->for($connection);
        $session = $this->request($connection, 'POST', $credentials->appId.'/uploads', ['query' => [
            'file_name'   => basename($fileName),
            'file_length' => strlen($contents),
            'file_type'   => $mimeType,
        ], 'headers' => ['Authorization' => 'OAuth '.$credentials->accessToken]]);
        $uploadId = trim((string) ($session['id'] ?? ''));
        if ('' === $uploadId || !str_starts_with($uploadId, 'upload:')) {
            throw new \RuntimeException('Meta did not return a valid upload session.');
        }

        $result = $this->request($connection, 'POST', $uploadId, [
            'body'    => $contents,
            'headers' => [
                'Authorization' => 'OAuth '.$credentials->accessToken,
                'Content-Type'  => $mimeType,
                'file_offset'   => '0',
            ],
        ]);
        $handle = trim((string) ($result['h'] ?? ''));
        if ('' === $handle) {
            throw new \RuntimeException('Meta did not return a profile-picture handle.');
        }

        return $handle;
    }

    private function request(MetaConnection $connection, string $method, string $path, array $options): array
    {
        $credentials = $this->credentials->for($connection);
        $this->rateLimiter->reserve($connection);
        $url = sprintf('https://graph.facebook.com/%s/%s', $credentials->graphVersion, ltrim($path, '/'));
        if (!isset($options['headers']['Authorization'])) {
            $options['auth_bearer'] = $credentials->accessToken;
        }
        $options['headers']['Accept'] = 'application/json';
        $options['timeout'] = 30;
        $response = $this->httpClient->request($method, $url, $options);
        $data = $response->toArray(false);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $error = is_array($data['error'] ?? null)
                ? $this->withoutTokens($data['error'])
                : ['message' => 'Meta Graph API request failed.'];
            $endpoint = $this->safeEndpoint($url);

            $this->logger?->error('Meta Graph API request failed.', [
                'method'        => $method,
                'endpoint'      => $endpoint,
                'http_status'   => $status,
                'code'          => $error['code'] ?? null,
                'error_subcode' => $error['error_subcode'] ?? null,
                'type'          => $error['type'] ?? null,
                'message'       => $error['message'] ?? null,
                'fbtrace_id'    => $error['fbtrace_id'] ?? null,
            ]);

            throw new MetaGraphApiException($method, $endpoint, $status, $error);
        }

        return $data;
    }

    private function safeEndpoint(string $url): string
    {
        return preg_replace(
            '/([?&](?:access_token|token)=)[^&]*/i',
            '$1[REDACTED]',
            $url,
        ) ?? $url;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function withoutTokens(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), ['token', 'access_token'], true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->withoutTokens($value);
            }
        }

        return $data;
    }
}
