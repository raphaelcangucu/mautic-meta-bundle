<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EmbeddedSignupApi
{
    public function __construct(private HttpClientInterface $http, private ConnectionCredentialProvider $credentials)
    {
    }

    /** @return array{token: string, expires: int} */
    public function exchange(MetaConnection $provider, string $code, string $redirect): array
    {
        $credentials = $this->credentials->for($provider);
        $base = 'https://graph.facebook.com/'.$credentials->graphVersion;
        try {
            $response = $this->http->request('GET', $base.'/oauth/access_token', ['query' => [
                'client_id' => $provider->getAppId(), 'client_secret' => $credentials->appSecret,
                'code' => $code, 'redirect_uri' => $redirect,
            ], 'timeout' => 20, 'max_redirects' => 0]);
            $data = $response->toArray(false);
            if ($response->getStatusCode() >= 400 || empty($data['access_token'])) {
                throw new \RuntimeException();
            }
            $token = (string) $data['access_token'];
            $response = $this->http->request('GET', $base.'/debug_token', ['auth_bearer' => $provider->getAppId().'|'.$credentials->appSecret,
                'query' => ['input_token' => $token], 'timeout' => 20, 'max_redirects' => 0]);
            $debug = $response->toArray(false)['data'] ?? [];
            if ($response->getStatusCode() >= 400 || true !== ($debug['is_valid'] ?? false) || ($debug['app_id'] ?? '') !== $provider->getAppId()
                || array_diff(['whatsapp_business_management', 'whatsapp_business_messaging'], $debug['scopes'] ?? [])) {
                throw new \RuntimeException();
            }
            $expires = (int) ($debug['expires_at'] ?? 0);
            if ($expires > 0 && $expires <= time()) {
                throw new \RuntimeException();
            }

            return ['token' => $token, 'expires' => $expires];
        } catch (\Throwable) {
            // HTTP exceptions may contain URLs with credentials and authorization codes.
            throw new \DomainException('A autorização não pôde ser validada. Inicie novamente o login da empresa na Meta.');
        }
    }
}
