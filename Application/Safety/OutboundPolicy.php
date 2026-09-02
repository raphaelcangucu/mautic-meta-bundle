<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Safety;

use Doctrine\DBAL\Connection;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;

/**
 * Local anti-abuse limits. Meta's own account/quality limits remain authoritative.
 */
final class OutboundPolicy
{
    public function __construct(private Connection $connection) {}

    public function assertAllowed(MetaAsset $asset, string $channel, string $recipient, string $messageType): void
    {
        $settings = $asset->getSettings();
        if (false === ($settings['anti_spam_enabled'] ?? true)) {
            throw new \DomainException('Outbound anti-spam protection cannot be disabled. Adjust its limits instead.');
        }

        $defaults = 'whatsapp' === $channel
            ? ['daily' => 250, 'hourly' => 50, 'recipient_daily' => 3, 'cooldown' => 60]
            : ['daily' => 50, 'hourly' => 20, 'recipient_daily' => 3, 'cooldown' => 300];

        $daily = $this->bounded($settings['daily_send_limit'] ?? null, $defaults['daily'], 1, $defaults['daily']);
        $hourly = $this->bounded($settings['hourly_send_limit'] ?? null, $defaults['hourly'], 1, min($daily, $defaults['hourly']));
        $recipientDaily = $this->bounded($settings['recipient_daily_limit'] ?? null, $defaults['recipient_daily'], 1, $defaults['recipient_daily']);
        $cooldown = $this->bounded($settings['recipient_cooldown_seconds'] ?? null, $defaults['cooldown'], $defaults['cooldown'], 86400);
        $assetId = (int) $asset->getId();

        if ($this->countSince($assetId, $channel, null, new \DateTimeImmutable('-24 hours')) >= $daily) {
            throw new \DomainException(sprintf('Local anti-spam daily limit reached for this %s asset (%d/24h).', $channel, $daily));
        }
        if ($this->countSince($assetId, $channel, null, new \DateTimeImmutable('-1 hour')) >= $hourly) {
            throw new \DomainException(sprintf('Local anti-spam hourly limit reached for this %s asset (%d/hour).', $channel, $hourly));
        }
        if ($this->countSince($assetId, $channel, $recipient, new \DateTimeImmutable('-24 hours')) >= $recipientDaily) {
            throw new \DomainException(sprintf('Local anti-spam recipient limit reached (%d messages/24h).', $recipientDaily));
        }
        if ($this->countSince($assetId, $channel, $recipient, new \DateTimeImmutable(sprintf('-%d seconds', $cooldown))) > 0) {
            throw new \DomainException(sprintf('Local anti-spam cooldown active for this recipient (%d seconds).', $cooldown));
        }

        if ('whatsapp' === $channel && 'template' !== $messageType && true === ($settings['enforce_customer_service_window'] ?? true)) {
            $recentInbound = (int) $this->connection->fetchOne(
                'SELECT COUNT(id) FROM meta_messages WHERE asset_id = :asset AND channel = :channel AND direction = :direction AND recipient = :recipient AND date_added >= :since',
                ['asset' => $assetId, 'channel' => $channel, 'direction' => 'inbound', 'recipient' => $recipient, 'since' => (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s')]
            );
            if (0 === $recentInbound) {
                throw new \DomainException('WhatsApp free-form messages require a customer interaction in the last 24 hours; use an approved template instead.');
            }
        }
    }

    private function countSince(int $assetId, string $channel, ?string $recipient, \DateTimeImmutable $since): int
    {
        $sql = "SELECT COUNT(id) FROM meta_messages WHERE asset_id = :asset AND channel = :channel AND direction = 'outbound' AND status <> 'failed' AND date_added >= :since";
        $params = ['asset' => $assetId, 'channel' => $channel, 'since' => $since->format('Y-m-d H:i:s')];
        if (null !== $recipient) {
            $sql .= ' AND recipient = :recipient';
            $params['recipient'] = $recipient;
        }

        return (int) $this->connection->fetchOne($sql, $params);
    }

    private function bounded(mixed $value, int $default, int $minimum, int $maximum): int
    {
        $value = null === $value || '' === $value ? $default : (int) $value;

        return max($minimum, min($maximum, $value));
    }
}
