<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaWebhookEvent;
use MauticPlugin\MauticMetaBundle\Entity\MetaWebhookEventRepository;

final class WebhookIngestor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MetaWebhookEventRepository $repository,
    ) {}

    public function ingest(MetaConnection $connection, array $payload): array
    {
        $objectType = (string) ($payload['object'] ?? 'unknown');
        $eventKey = $this->eventKey($payload);
        $existing = $this->repository->findOneBy(['connection' => $connection, 'eventKey' => $eventKey]);
        if ($existing instanceof MetaWebhookEvent) {
            // A request can be interrupted after the durable ingest flush and before
            // processing starts. Only a fully processed event is a true duplicate.
            if (in_array($existing->getStatus(), ['failed', 'received'], true)) {
                $existing->setStatus('received')->setLastError(null);
                $this->entityManager->flush();

                return ['duplicate' => false, 'retry' => true, 'eventId' => $existing->getId(), 'eventKey' => $eventKey];
            }

            return ['duplicate' => true, 'retry' => false, 'eventId' => $existing->getId(), 'eventKey' => $eventKey];
        }
        $event = (new MetaWebhookEvent())
            ->setConnection($connection)
            ->setEventKey($eventKey)
            ->setObjectType($objectType)
            ->setPayload($payload);
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return ['duplicate' => false, 'retry' => false, 'eventId' => $event->getId(), 'eventKey' => $eventKey];
    }

    public function complete(int $eventId, ?\Throwable $error = null): void
    {
        $event = $this->repository->find($eventId);
        if (!$event instanceof MetaWebhookEvent) { return; }
        $event->setAttempts($event->getAttempts() + 1)->setProcessedAt(new \DateTime());
        if (null === $error) { $event->setStatus('processed')->setLastError(null); } else { $event->setStatus('failed')->setLastError($error->getMessage()); }
        $this->entityManager->flush();
    }

    private function eventKey(array $payload): string
    {
        $entry = $payload['entry'][0] ?? [];
        $change = $entry['changes'][0] ?? [];
        $value = $change['value'] ?? [];
        $candidate = $value['messages'][0]['id'] ?? $value['statuses'][0]['id'] ?? $value['comments'][0]['id'] ?? null;

        return null === $candidate ? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)) : (string) $candidate.':'.hash('sha256', json_encode($change, JSON_THROW_ON_ERROR));
    }
}
