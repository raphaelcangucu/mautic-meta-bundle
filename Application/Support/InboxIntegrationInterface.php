<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Support;

use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

/** Optional boundary implemented by a support inbox plugin. */
interface InboxIntegrationInterface
{
    public function ownsSupportInbox(): bool;

    public function messagePersisted(MetaMessage $message): void;

    public function automationAllowed(MetaAsset $asset, string $recipient): bool;

    /** @template T @param callable():T $operation @return T */
    public function runAutomationGuarded(MetaAsset $asset, string $recipient, callable $operation): mixed;

    public function outboundJobChanged(MetaOutboundJob $job): void;
}
