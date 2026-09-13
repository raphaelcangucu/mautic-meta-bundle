<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Instagram;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CampaignBundle\Entity\Lead as CampaignMember;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLogRepository;
use Mautic\CampaignBundle\Entity\LeadRepository as CampaignMemberRepository;
use Mautic\CampaignBundle\Executioner\RealTimeExecutioner;
use Mautic\CampaignBundle\Membership\MembershipManager;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use MauticPlugin\MauticMetaBundle\Application\Automation\CampaignMessageDispatcher;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramCommentAutomation;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramCommentMatcher;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\MetaEvents;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class InstagramCommentAutomationTest extends TestCase
{
    public function testFailureThenReplayDoesNotRejoinAndNewCommentUsesNewRotation(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('isPublished')->willReturn(true);
        $campaign->method('isDeleted')->willReturn(false);
        $campaign->method('allowRestart')->willReturn(true);
        $action = $this->createMock(Event::class);
        $action->method('isDeleted')->willReturn(false);
        $action->method('getType')->willReturn('meta.instagram.comment.private_reply');
        $action->method('getProperties')->willReturn(['message' => 'Report URL']);
        $decision = $this->createMock(Event::class);
        $decision->method('isDeleted')->willReturn(false);
        $decision->method('getId')->willReturn(12);
        $decision->method('getCampaign')->willReturn($campaign);
        $decision->method('getProperties')->willReturn(['asset_id' => 4, 'media_id' => '123456789', 'keyword' => 'relatorio']);
        $decision->method('getPositiveChildren')->willReturn(new ArrayCollection([$action]));
        $events = $this->createMock(EventRepository::class);
        $events->method('findBy')->willReturn([$decision]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects(self::once())->method('saveEntity')->willReturnCallback(static function (Lead $lead): void { $lead->setId(17); });
        $member = null;
        $members = $this->createMock(CampaignMemberRepository::class);
        $members->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$member): ?CampaignMember { return $member; });
        $membership = $this->createMock(MembershipManager::class);
        $membership->expects(self::exactly(2))->method('addContact')->with(self::isInstanceOf(Lead::class), $campaign, true)->willReturnCallback(static function (Lead $lead) use (&$member, $campaign): void {
            if (!$member instanceof CampaignMember) {
                $member = (new CampaignMember())->setRotation(1);
                $member->setLead($lead);
                $member->setCampaign($campaign);
            } else {
                $member->setManuallyRemoved(false);
                $member->setDateLastExited();
                $member->startNewRotation();
            }
        });
        $logs = [];
        $eventLogs = $this->createMock(LeadEventLogRepository::class);
        $eventLogs->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$logs): ?LeadEventLog { return $logs[(int) ($criteria['rotation'] ?? 0)] ?? null; });

        $attempt = 0;
        $executioner = $this->createMock(RealTimeExecutioner::class);
        $executioner->expects(self::exactly(3))->method('execute')->with(MetaEvents::CAMPAIGN_INSTAGRAM_COMMENT_TYPE)->willReturnCallback(static function (string $type, MetaMessage $message) use (&$attempt, &$logs, &$member, $decision): void {
            ++$attempt;
            if (1 === $attempt) {
                throw new \RuntimeException('Temporary campaign failure');
            }
            $rotation = $member->getRotation();
            $logs[$rotation] = (new LeadEventLog())->setEvent($decision)->setLead($message->getContact())->setRotation($rotation)->setMetadata(['meta_comment_id' => (string) $message->getPayload()['commentId']]);
        });
        $campaigns = new CampaignMessageDispatcher($this->createMock(ContactTracker::class), $executioner, $this->createMock(LoggerInterface::class));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(7))->method('persist');
        $automation = new InstagramCommentAutomation($events, $members, $eventLogs, new InstagramCommentMatcher(), $leadModel, $membership, $entityManager, $campaigns);
        $asset = new MetaAsset(4);
        $identity = (new MetaContactIdentity())->setAsset($asset)->setExternalId('ig-user-1');
        $comment = $this->comment($asset, 'comment-1');

        try {
            $automation->handle($comment, $identity);
            self::fail('The first campaign attempt should fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('could not evaluate', $exception->getMessage());
        }
        self::assertSame(1, $member->getRotation());
        $automation->handle($comment, $identity);
        $automation->handle($comment, $identity);
        self::assertSame(1, $member->getRotation(), 'Successful webhook replay must not restart membership.');

        $member->setManuallyRemoved(true);
        $member->setDateLastExited(new \DateTime());
        $automation->handle($this->comment($asset, 'comment-2'), $identity);
        self::assertSame(2, $member->getRotation(), 'A different comment may restart an eligible campaign.');
        self::assertCount(2, $logs);
        $member->setManuallyRemoved(true);
        $member->setDateLastExited();
        $automation->handle($this->comment($asset, 'comment-3')->setContact($identity->getContact()), $identity);
        self::assertSame(2, $member->getRotation(), 'Explicit manual removal must still prevent re-entry.');
    }

    public function testUnpublishedAndOutOfWindowCampaignsCannotCreateAContact(): void
    {
        $campaigns = [
            (new Campaign())->setIsPublished(false),
            (new Campaign())->setIsPublished(true)->setPublishUp(new \DateTime('+1 day')),
            (new Campaign())->setIsPublished(true)->setPublishDown(new \DateTime('-1 day')),
        ];
        foreach ($campaigns as $campaign) {
            self::assertFalse($campaign->isPublished());
            $decision = $this->createMock(Event::class);
            $decision->method('getCampaign')->willReturn($campaign);
            $events = $this->createMock(EventRepository::class);
            $events->method('findBy')->willReturn([$decision]);
            $leads = $this->createMock(LeadModel::class);
            $leads->expects(self::never())->method('saveEntity');
            $executioner = $this->createMock(RealTimeExecutioner::class);
            $executioner->expects(self::never())->method('execute');
            $dispatcher = new CampaignMessageDispatcher($this->createMock(ContactTracker::class), $executioner, $this->createMock(LoggerInterface::class));
            $automation = new InstagramCommentAutomation($events, $this->createMock(CampaignMemberRepository::class), $this->createMock(LeadEventLogRepository::class), new InstagramCommentMatcher(), $leads, $this->createMock(MembershipManager::class), $this->createMock(EntityManagerInterface::class), $dispatcher);
            $asset = new MetaAsset(4);
            $automation->handle($this->comment($asset, 'comment-1'), (new MetaContactIdentity())->setAsset($asset));
        }
    }

    private function comment(MetaAsset $asset, string $commentId): MetaMessage
    {
        return (new MetaMessage())->setAsset($asset)->setChannel('instagram')->setDirection('inbound')->setMessageType('comment')->setPayload(['commentId' => $commentId, 'mediaId' => '123456789', 'text' => 'RELATÓRIO']);
    }
}
