<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionCredentialProvider;
use MauticPlugin\MauticMetaBundle\Application\Webhook\InstagramWebhookProcessor;
use MauticPlugin\MauticMetaBundle\Application\Webhook\WebhookIngestor;
use MauticPlugin\MauticMetaBundle\Application\Webhook\WhatsAppWebhookProcessor;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use MauticPlugin\MauticMetaBundle\Security\WebhookSignatureVerifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebhookController
{
    public function __construct(
        private MetaConnectionRepository $connections,
        private ConnectionCredentialProvider $credentials,
        private WebhookSignatureVerifier $verifier,
        private WebhookIngestor $ingestor,
        private WhatsAppWebhookProcessor $whatsAppProcessor,
        private InstagramWebhookProcessor $instagramProcessor,
    ) {}

    public function handle(int $connectionId, Request $request): Response
    {
        $connection = $this->connections->find($connectionId);
        if (!$connection instanceof MetaConnection || !$connection->isPublished()) {
            return new JsonResponse(['error' => 'Connection unavailable.'], Response::HTTP_NOT_FOUND);
        }
        $credentials = $this->credentials->for($connection);
        if ($request->isMethod('GET')) {
            $mode = (string) $request->query->get('hub.mode', $request->query->get('hub_mode', ''));
            $token = (string) $request->query->get('hub.verify_token', $request->query->get('hub_verify_token', ''));
            if (!$this->verifier->verifyChallenge($mode, $token, $credentials->verifyToken)) {
                return new Response('Forbidden', Response::HTTP_FORBIDDEN);
            }

            return new Response((string) $request->query->get('hub.challenge', $request->query->get('hub_challenge', '')), Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }
        $payload = $request->getContent();
        if (!$this->verifier->verify($payload, (string) $request->headers->get('X-Hub-Signature-256'), $credentials->appSecret)) {
            return new JsonResponse(['error' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return new JsonResponse(['error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $ingested = $this->ingestor->ingest($connection, $decoded);
        if (true === $ingested['duplicate']) {
            $processed = ['duplicate' => true];
        } else {
            try {
                if ('whatsapp_business_account' === ($decoded['object'] ?? null)) { $processed = $this->whatsAppProcessor->process($decoded); }
                elseif ('instagram' === ($decoded['object'] ?? null)) { $processed = $this->instagramProcessor->process($decoded); }
                else { $processed = ['ignored' => true]; }
                $this->ingestor->complete((int) $ingested['eventId']);
            } catch (\Throwable $exception) {
                $this->ingestor->complete((int) $ingested['eventId'], $exception);

                return new JsonResponse(['received' => false, 'error' => 'Webhook processing failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new JsonResponse(['received' => true, 'ingestion' => $ingested, 'processing' => $processed]);
    }
}
