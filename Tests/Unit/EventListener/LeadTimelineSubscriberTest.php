<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\EventListener;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\LeadTimelineEvent;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;
use MauticPlugin\MauticMetaBundle\EventListener\LeadTimelineSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LeadTimelineSubscriberTest extends TestCase
{
    public function testAddsAssociatedMessagesToContactTimeline(): void
    {
        $message = (new MetaMessage(41))->setChannel('whatsapp')->setDirection('inbound')->setMessageType('text')->setStatus('received')->setRecipient('5511999999999');
        $repository = $this->createMock(MetaMessageRepository::class);
        $repository->expects(self::exactly(2))->method('getContactTimeline')->willReturnCallback(
            static fn (int $contactId, string $channel): array => 'whatsapp' === $channel ? ['results' => [$message], 'total' => 1] : ['results' => [], 'total' => 0],
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $contact = (new Lead())->setId(7);
        $event = new LeadTimelineEvent($contact);

        (new LeadTimelineSubscriber($repository, $translator))->onTimelineGenerate($event);

        $events = $event->getEvents();
        self::assertCount(1, $events);
        self::assertSame('meta.whatsapp.41', $events[0]['eventId']);
        self::assertSame(7, $events[0]['contactId']);
    }
}
