<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;

final class WhatsAppOperationalHealth
{
    private const CONFIRMED_DELIVERY_STATUSES = ['delivered', 'read'];
    private const META_ACCEPTED_STATUSES = ['accepted', 'sent', 'delivered', 'read'];
    private const RECENT_WINDOW = 'P7D';

    public function __construct(private MetaMessageRepository $messages)
    {
    }

    /**
     * Build operational evidence separately from the connection's authorization
     * status. A successful Graph API diagnostic does not prove message delivery.
     *
     * @param iterable<MetaAsset> $assets
     *
     * @return array<int, array<string, mixed>> keyed by asset id
     */
    public function forAssets(iterable $assets, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $recentSince = $now->sub(new \DateInterval(self::RECENT_WINDOW));
        $health = [];

        foreach ($assets as $asset) {
            if (!$asset instanceof MetaAsset || AssetType::WhatsAppPhoneNumber !== $asset->getType() || null === $asset->getId()) {
                continue;
            }

            $inbound = $this->evidence($this->messages->findLatestForAssetDirection($asset, 'inbound'), $recentSince);
            $outbound = $this->evidence($this->messages->findLatestForAssetDirection($asset, 'outbound'), $recentSince);
            $state = $this->state($inbound, $outbound);

            $health[$asset->getId()] = [
                'state'               => $state,
                'hasOperationalProof' => 'healthy' === $state,
                'phoneSuffix'         => $this->phoneSuffix($asset),
                'displayNumber'       => $this->maskedNumber($asset),
                'inbound'             => $inbound,
                'outbound'            => $outbound,
            ];
        }

        return $health;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function evidence(?MetaMessage $message, \DateTimeImmutable $recentSince): ?array
    {
        if (!$message instanceof MetaMessage) {
            return null;
        }

        $occurredAt = $message->getDateAdded();
        $observedAt = $message->getDateModified() ?? $occurredAt;

        return [
            'status'            => $message->getStatus(),
            'occurredAt'        => $occurredAt,
            'observedAt'        => $observedAt,
            'recent'            => $occurredAt >= $recentSince,
            'deliveryConfirmed' => in_array($message->getStatus(), self::CONFIRMED_DELIVERY_STATUSES, true),
            'acceptedByMeta'    => in_array($message->getStatus(), self::META_ACCEPTED_STATUSES, true),
        ];
    }

    /**
     * @param array<string, mixed>|null $inbound
     * @param array<string, mixed>|null $outbound
     */
    private function state(?array $inbound, ?array $outbound): string
    {
        if ('failed' === ($outbound['status'] ?? null) && true === ($outbound['recent'] ?? false)) {
            return 'degraded';
        }

        $receiving = true === ($inbound['recent'] ?? false);
        $deliveryConfirmed = true === ($outbound['recent'] ?? false) && true === ($outbound['deliveryConfirmed'] ?? false);
        if ($receiving && $deliveryConfirmed) {
            return 'healthy';
        }
        if ($receiving && true === ($outbound['recent'] ?? false)) {
            return 'awaiting_delivery';
        }
        if ($receiving) {
            return 'receiving';
        }
        if ($deliveryConfirmed) {
            return 'sending';
        }
        if (true === ($outbound['recent'] ?? false)) {
            return 'sending_pending';
        }
        if (null !== $inbound || null !== $outbound) {
            return 'stale';
        }

        return 'no_evidence';
    }

    private function phoneSuffix(MetaAsset $asset): string
    {
        $digits = preg_replace('/\D+/', '', (string) $asset->getPhoneNumber()) ?? '';

        return '' === $digits ? '' : substr($digits, -4);
    }

    private function maskedNumber(MetaAsset $asset): string
    {
        $suffix = $this->phoneSuffix($asset);

        return '' === $suffix ? 'WhatsApp' : 'WhatsApp •••• '.$suffix;
    }
}
