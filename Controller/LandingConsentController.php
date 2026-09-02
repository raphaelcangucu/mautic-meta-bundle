<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use MauticPlugin\MauticMetaBundle\Application\Consent\ConsentJobQueue;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class LandingConsentController
{
    public function __construct(
        private MetaConnectionRepository $connections,
        private MetaAssetRepository $assets,
        private CredentialVault $vault,
        private ConsentJobQueue $queue,
    ) {
    }

    public function capture(int $connectionId, int $assetId, Request $request): JsonResponse
    {
        $connection = $this->connections->find($connectionId);
        $asset = $this->assets->find($assetId);
        if (
            !$connection instanceof MetaConnection
            || !$asset instanceof MetaAsset
            || $asset->getConnection()->getId() !== $connection->getId()
        ) {
            return new JsonResponse(['error' => ['field' => 'assetId', 'message' => 'Connection or asset not found.']], 404);
        }

        $body = $request->getContent();
        $timestamp = (string) $request->headers->get('X-Mautic-Meta-Timestamp');
        $signature = (string) $request->headers->get('X-Mautic-Meta-Signature');
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return new JsonResponse(['error' => ['field' => 'timestamp', 'message' => 'Expired timestamp.']], 401);
        }

        $sealedSecret = (string) ($connection->getSettings()['consent_source_secret'] ?? '');
        if ('' === $sealedSecret) {
            return new JsonResponse(['error' => ['field' => 'connection', 'message' => 'Landing consent integration is not configured.']], 503);
        }
        $expected = 'sha256='.hash_hmac(
            'sha256',
            $timestamp.'.'.$body,
            $this->vault->open($sealedSecret),
        );
        if (!hash_equals($expected, $signature)) {
            return new JsonResponse(['error' => ['field' => 'signature', 'message' => 'Invalid signature.']], 401);
        }

        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \JsonException('JSON body must be an object.');
            }
            if (true !== ($payload['consent'] ?? null)) {
                throw new \InvalidArgumentException('consent must be the boolean true.');
            }
            foreach (['phone', 'consentAt', 'business', 'purpose', 'source', 'consentText', 'consentVersion', 'externalSubmissionId'] as $field) {
                if ('' === trim((string) ($payload[$field] ?? ''))) {
                    throw new \InvalidArgumentException($field.' is required.');
                }
            }
            $job = $this->queue->enqueue($asset, $payload);
        } catch (\InvalidArgumentException|\JsonException $exception) {
            return new JsonResponse(['error' => ['field' => 'body', 'message' => $exception->getMessage()]], 422);
        }

        return new JsonResponse([
            'event'  => 'LandingWhatsAppConsentCaptured',
            'status' => $job->getStatus(),
            'jobId'  => $job->getId(),
        ], Response::HTTP_ACCEPTED);
    }
}
