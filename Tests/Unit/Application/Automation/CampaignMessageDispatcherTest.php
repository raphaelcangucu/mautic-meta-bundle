<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Automation;

use Mautic\CampaignBundle\Executioner\RealTimeExecutioner;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Tracker\ContactTracker;
use MauticPlugin\MauticMetaBundle\Application\Automation\CampaignMessageDispatcher;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\MetaEvents;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CampaignMessageDispatcherTest extends TestCase
{
    public function testRunsRealtimeDecisionAsAssociatedSystemContact(): void
    {
        $contact = (new Lead())->setId(17);
        $message = (new MetaMessage(9))->setContact($contact)->setAsset(new MetaAsset(3))->setChannel('whatsapp');
        $tracker = $this->createMock(ContactTracker::class);
        $tracker->expects(self::exactly(2))->method('setUseSystemContact')->with(self::callback(static fn (?bool $value): bool => true === $value || null === $value));
        $tracker->expects(self::exactly(2))->method('setSystemContact')->with(self::callback(static fn (?Lead $value): bool => $contact === $value || null === $value));
        $executioner = $this->createMock(RealTimeExecutioner::class);
        $executioner->expects(self::once())->method('execute')->with(MetaEvents::CAMPAIGN_MESSAGE_TYPE, $message, 'whatsapp', 3);

        (new CampaignMessageDispatcher($tracker, $executioner, $this->createMock(LoggerInterface::class)))->dispatch($message);
    }
}
