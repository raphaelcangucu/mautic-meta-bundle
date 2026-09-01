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

    public function dispatch(MetaMessage $message): void
    {
        $contact = $message->getContact();
        if (null === $contact || 0 >= $contact->getId()) { return; }
        try {
            $this->contacts->setUseSystemContact(true);
            $this->contacts->setSystemContact($contact);
            $this->executioner->execute(MetaEvents::CAMPAIGN_MESSAGE_TYPE, $message, $message->getChannel(), $message->getAsset()->getId());
        } catch (\Throwable $exception) {
            $this->logger->error('Could not dispatch Meta message campaign decision.', ['message_id' => $message->getId(), 'error' => $exception->getMessage()]);
        } finally {
            $this->contacts->setSystemContact();
            $this->contacts->setUseSystemContact(null);
        }
    }
}
