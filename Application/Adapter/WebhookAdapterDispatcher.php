<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Adapter;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaAdapterDelivery;
use MauticPlugin\MauticMetaBundle\Entity\MetaAdapterDeliveryRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

final class WebhookAdapterDispatcher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MetaAdapterDeliveryRepository $deliveries,
    ) {
    }

    public function dispatch(MetaMessage $message, string $event): void
    {
        $connection = $message->getAsset()->getConnection();
        $adapters = $connection->getSettings()['webhook_adapters'] ?? [];
        foreach (is_array($adapters) ? $adapters : [] as $adapter) {
            if (!is_array($adapter) || false === ($adapter['enabled'] ?? true)) {
                continue;
            }
            if (
                !in_array($event, $adapter['events'] ?? [], true)
                || !in_array($message->getChannel(), $adapter['channels'] ?? [], true)
            ) {
                continue;
            }

            $payload = $this->payload($message, $event, (int) $connection->getId());
            $existingDelivery = $this->deliveries->findOneBy([
                'eventId'     => $payload['eventId'],
                'adapterName' => (string) $adapter['name'],
            ]);
            if ($existingDelivery instanceof MetaAdapterDelivery) {
                continue;
            }

            $delivery = (new MetaAdapterDelivery())
                ->setConnection($connection)
                ->setMessage($message)
                ->setAdapterName((string) $adapter['name'])
                ->setUrl((string) $adapter['url'])
                ->setSealedSecret((string) $adapter['sealed_secret'])
                ->setEventName($event)
                ->setEventId($payload['eventId'])
                ->setPayload($payload)
                ->setMaxAttempts(min(10, max(1, (int) ($adapter['maxAttempts'] ?? 5))))
                ->setTimeout((int) ($adapter['timeout'] ?? 5));
            $this->entityManager->persist($delivery);
        }

        $this->entityManager->flush();
    }

    private function payload(MetaMessage $message, string $event, int $connectionId): array
    {
        return [
            'specVersion' => '1.0',
            'eventId'     => hash('sha256', $event.':'.$message->getId().':'.$message->getStatus()),
            'event'       => $event,
            'occurredAt'  => (new \DateTimeImmutable())->format(DATE_ATOM),
            'connectionId' => $connectionId,
            'conversation' => null === $message->getConversation() ? null : [
                'id'     => $message->getConversation()->getId(),
                'status' => $message->getConversation()->getStatus(),
            ],
            'channel' => $message->getChannel(),
            'assetId' => $message->getAsset()->getId(),
            'message' => [
                'id'         => $message->getId(),
                'externalId' => $message->getExternalId(),
                'direction'  => $message->getDirection(),
                'type'       => $message->getMessageType(),
                'recipient'  => $message->getRecipient(),
                'status'     => $message->getStatus(),
                'payload'    => $message->getPayload(),
            ],
            'contact' => null === $message->getContact() ? null : [
                'id' => $message->getContact()->getId(),
            ],
        ];
    }
}
