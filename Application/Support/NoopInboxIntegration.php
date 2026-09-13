<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Support;

use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

final class NoopInboxIntegration implements InboxIntegrationInterface
{
    public function ownsSupportInbox(): bool
    {
        return false;
    }

    public function messagePersisted(MetaMessage $message): void
    {
    }

    public function automationAllowed(MetaAsset $asset, string $recipient): bool
    {
        return true;
    }

    public function runAutomationGuarded(MetaAsset $asset, string $recipient, callable $operation): mixed
    {
        return $operation();
    }

    public function outboundJobChanged(MetaOutboundJob $job): void
    {
    }
}
