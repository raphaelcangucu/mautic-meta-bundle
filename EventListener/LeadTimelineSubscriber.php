<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\EventListener;

use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LeadTimelineSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MetaMessageRepository $messages,
        private TranslatorInterface $translator
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [LeadEvents::TIMELINE_ON_GENERATE => ['onTimelineGenerate', 0]];
    }

    public function onTimelineGenerate(LeadTimelineEvent $event): void
    {
        $contactId = $event->getLeadId();
        if (null === $contactId) { return; }
        foreach (['whatsapp' => 'ri-whatsapp-line', 'instagram' => 'ri-instagram-line'] as $channel => $icon) {
            $key = 'meta.'.$channel;
            $name = $this->translator->trans('mautic.meta.timeline.'.$channel);
            $event->addEventType($key, $name);
            if (!$event->isApplicable($key)) { continue; }
            $messages = $this->messages->getContactTimeline($contactId, $channel, $event->getQueryOptions());
            $event->addToCounter($key, $messages['total']);
            if ($event->isEngagementCount()) { continue; }
            foreach ($messages['results'] as $message) {
                $event->addEvent($this->timelineEvent($key, $name, $icon, $contactId, $message));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineEvent(string $key, string $name, string $icon, int $contactId, MetaMessage $message): array
    {
        return [
            'event' => $key,
            'eventId' => $key.'.'.$message->getId(),
            'eventLabel' => sprintf('%s · %s · %s', ucfirst($message->getDirection()), $message->getMessageType(), $message->getStatus()),
            'eventType' => $name,
            'timestamp' => $message->getDateAdded(),
            'icon' => $icon,
            'contactId' => $contactId,
            'extra' => ['message' => $message],
            'contentTemplate' => '@MauticMeta/Timeline/message.html.twig',
        ];
    }
}
