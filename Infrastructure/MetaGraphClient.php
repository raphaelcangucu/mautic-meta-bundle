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

    private function request(MetaConnection $connection, string $method, string $path, array $options): array
    {
        $credentials = $this->credentials->for($connection);
        $this->rateLimiter->reserve($connection);
        $url = sprintf('https://graph.facebook.com/%s/%s', $credentials->graphVersion, ltrim($path, '/'));
        $options['auth_bearer'] = $credentials->accessToken;
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
