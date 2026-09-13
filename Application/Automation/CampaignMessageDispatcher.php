<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Automation;

use Mautic\CampaignBundle\Executioner\RealTimeExecutioner;
use Mautic\LeadBundle\Tracker\ContactTracker;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\MetaEvents;
use Psr\Log\LoggerInterface;

final class CampaignMessageDispatcher
{
    public function __construct(
        private ContactTracker $contacts,
        private RealTimeExecutioner $executioner,
        private LoggerInterface $logger
    ) {}

    public function dispatch(MetaMessage $message, string $eventType = MetaEvents::CAMPAIGN_MESSAGE_TYPE): bool
    {
        $contact = $message->getContact();
        if (null === $contact || 0 >= $contact->getId()) { return false; }
        try {
            $this->contacts->setUseSystemContact(true);
            $this->contacts->setSystemContact($contact);
            $this->executioner->execute($eventType, $message, $message->getChannel(), $message->getAsset()->getId());
            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Could not dispatch Meta message campaign decision.', ['message_id' => $message->getId(), 'error' => $exception->getMessage()]);
            return false;
        } finally {
            $this->contacts->setSystemContact();
            $this->contacts->setUseSystemContact(null);
        }
    }
}
