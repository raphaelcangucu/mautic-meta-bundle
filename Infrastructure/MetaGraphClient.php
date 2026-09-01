<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Infrastructure;

use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionCredentialProvider;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MetaGraphClient implements MetaGraphClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ConnectionCredentialProvider $credentials,
        private ConnectionRateLimiter $rateLimiter,
    ) {}

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
            $message = (string) ($data['error']['message'] ?? 'Meta Graph API request failed.');
            $code = (string) ($data['error']['code'] ?? $status);
            $detail = sprintf('%s (Meta code %s, HTTP %d)', $message, $code, $status);
            if (429 !== $status && $status < 500) { throw new \InvalidArgumentException($detail); }
            throw new \RuntimeException($detail);
        }

        return $data;
    }
}
