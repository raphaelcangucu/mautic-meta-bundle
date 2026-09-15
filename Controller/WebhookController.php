<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
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
        private EntityManagerInterface $entityManager,
        private \MauticPlugin\MauticMetaBundle\Application\Webhook\FacebookWebhookProcessor $facebookProcessor,
        private ?\MauticPlugin\MauticMetaBundle\Application\Connection\ProviderWebhookRouter $providerRouter = null,
    ) {
    }

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

        if ('whatsapp_business_account' === ($decoded['object'] ?? null) && null !== $this->providerRouter) {
            foreach ($this->providerRouter->route($connection, $decoded) as $routed) {
                $response = $this->ingestAndProcess($routed['connection'], $routed['payload']);
                if ($response->getStatusCode() >= 400) {
                    return $response;
                }
            }

            return new JsonResponse(['received' => true]);
        }

        if (!in_array($decoded['object'] ?? null, ['instagram', 'page'], true)) {
            return $this->ingestAndProcess($connection, $decoded);
        }

        $lock = 'meta_hook_'.hash('sha1', $connectionId.':'.$payload);
        $db = $this->entityManager->getConnection();
        if (1 !== (int) $db->fetchOne('SELECT GET_LOCK(:lock_name, 10)', ['lock_name' => $lock])) {
            return new JsonResponse(['received' => false, 'error' => 'Webhook is being processed.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        try {
            return $this->ingestAndProcess($connection, $decoded);
        } finally {
            $db->fetchOne('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lock]);
        }
    }

    /** @param array<string, mixed> $decoded */
    private function ingestAndProcess(MetaConnection $connection, array $decoded): Response
    {
        $ingested = $this->ingestor->ingest($connection, $decoded);
        if (true === $ingested['duplicate']) {
            $processed = ['duplicate' => true];
        } else {
            try {
                if ('whatsapp_business_account' === ($decoded['object'] ?? null)) {
                    $processed = $this->whatsAppProcessor->process($decoded, $connection);
                } elseif ('instagram' === ($decoded['object'] ?? null)) {
                    $processed = $this->instagramProcessor->process($decoded, $connection);
                } elseif ('page' === ($decoded['object'] ?? null)) {
                    $processed = $this->facebookProcessor->process($decoded, $connection);
                } else {
                    $processed = ['ignored' => true];
                }
                $this->ingestor->complete((int) $ingested['eventId']);
            } catch (\Throwable $exception) {
                // A failed transactional campaign execution can close Doctrine's
                // entity manager. The durable "received" event remains retryable.
                if ($this->entityManager->isOpen()) {
                    $this->ingestor->complete((int) $ingested['eventId'], $exception);
                }

                return new JsonResponse(['received' => false, 'error' => 'Webhook processing failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new JsonResponse(['received' => true, 'ingestion' => $ingested, 'processing' => $processed]);
    }
}
