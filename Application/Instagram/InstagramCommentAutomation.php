<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Instagram;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CampaignBundle\Entity\Lead as CampaignMember;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLogRepository;
use Mautic\CampaignBundle\Entity\LeadRepository as CampaignMemberRepository;
use Mautic\CampaignBundle\Membership\MembershipManager;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticMetaBundle\Application\Automation\CampaignMessageDispatcher;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\MetaEvents;

final class InstagramCommentAutomation
{
    public function __construct(
        private EventRepository $events,
        private CampaignMemberRepository $members,
        private LeadEventLogRepository $eventLogs,
        private InstagramCommentMatcher $matcher,
        private LeadModel $leads,
        private MembershipManager $membership,
        private EntityManagerInterface $entityManager,
        private CampaignMessageDispatcher $campaigns,
    ) {
    }

    public static function idempotencyKey(int $assetId, string $commentId): string
    {
        return 'igc:'.hash('sha256', 'instagram-comment-private-reply:'.$assetId.':'.$commentId);
    }

    public static function publicReplyIdempotencyKey(int $assetId, string $commentId): string
    {
        return 'igc-public:'.hash('sha256', 'instagram-comment-public-reply:'.$assetId.':'.$commentId);
    }

    public function handle(MetaMessage $message, MetaContactIdentity $identity): void
    {
        $commentId = (string) ($message->getPayload()['commentId'] ?? '');
        if ('' === $commentId) {
            return;
        }

        $matching = [];
        foreach ($this->events->findBy(['type' => MetaEvents::CAMPAIGN_INSTAGRAM_COMMENT_TYPE], ['id' => 'ASC']) as $decision) {
            if (!$decision instanceof Event || $decision->isDeleted() || !$decision->getCampaign()->isPublished() || $decision->getCampaign()->isDeleted() || !$this->matcher->matches($message, $decision->getProperties())) {
                continue;
            }
            foreach ($decision->getPositiveChildren() as $action) {
                if ($action instanceof Event && !$action->isDeleted() && 'meta.instagram.comment.private_reply' === $action->getType() && '' !== trim((string) ($action->getProperties()['message'] ?? ''))) {
                    $matching[(int) $decision->getId()] = $decision;
                }
            }
        }
        if ([] === $matching) {
            return;
        }

        $contact = $identity->getContact();
        if (!$contact instanceof Lead) {
            $contact = new Lead();
            $this->leads->saveEntity($contact);
            $identity->setContact($contact);
            $message->setContact($contact);
            $this->entityManager->persist($identity);
            $this->entityManager->persist($message);
            $this->entityManager->flush();
        } elseif ($message->getContact()?->getId() !== $contact->getId()) {
            $message->setContact($contact);
            $this->entityManager->persist($message);
            $this->entityManager->flush();
        }

        $pending = [];
        foreach ($matching as $decision) {
            $member = $this->members->findOneBy(['lead' => $contact, 'campaign' => $decision->getCampaign()]);
            if ($member instanceof CampaignMember && $member->getManuallyRemoved() && null === $member->getDateLastExited()) {
                continue;
            }

            $payload = $message->getPayload();
            $claims = (array) ($payload['instagram_comment_claims'] ?? []);
            $key = (string) $decision->getId();
            $claim = $claims[$key] ?? null;
            if (!is_array($claim)) {
                $previousRotation = $member instanceof CampaignMember ? (int) $member->getRotation() : 0;
                $previousLog = $member instanceof CampaignMember ? $this->eventLogs->findOneBy(['event' => $decision, 'lead' => $contact, 'rotation' => $previousRotation]) : null;
                $requiresRestart = $previousLog instanceof LeadEventLog;
                if ($requiresRestart && !$decision->getCampaign()->allowRestart()) {
                    continue;
                }
                $claim = ['state' => 'claimed', 'previous_rotation' => $previousRotation, 'requires_restart' => $requiresRestart];
                $claims[$key] = $claim;
                $payload['instagram_comment_claims'] = $claims;
                $message->setPayload($payload);
                $this->entityManager->persist($message);
                $this->entityManager->flush();
            }

            if ('joined' !== ($claim['state'] ?? null)) {
                $previousRotation = (int) ($claim['previous_rotation'] ?? 0);
                $requiresRestart = (bool) ($claim['requires_restart'] ?? false);
                $joined = $member instanceof CampaignMember && !$member->getManuallyRemoved()
                    && (!$requiresRestart || (int) $member->getRotation() > $previousRotation);
                if (!$joined) {
                    // Comment-triggered membership is independent of campaign segments.
                    $this->membership->addContact($contact, $decision->getCampaign(), true);
                    $member = $this->members->findOneBy(['lead' => $contact, 'campaign' => $decision->getCampaign()]);
                }
                if (!$member instanceof CampaignMember || $member->getManuallyRemoved() || ($requiresRestart && (int) $member->getRotation() <= $previousRotation)) {
                    throw new \RuntimeException('Instagram commenter could not join the configured Mautic campaign.');
                }
                $claim['state'] = 'joined';
                $claim['rotation'] = (int) $member->getRotation();
                $payload = $message->getPayload();
                $claims = (array) ($payload['instagram_comment_claims'] ?? []);
                $claims[$key] = $claim;
                $payload['instagram_comment_claims'] = $claims;
                $message->setPayload($payload);
                $this->entityManager->persist($message);
                $this->entityManager->flush();
            }

            $log = $this->eventLogs->findOneBy(['event' => $decision, 'lead' => $contact, 'rotation' => (int) ($claim['rotation'] ?? 0)]);
            if ($log instanceof LeadEventLog && $commentId === (string) ($log->getMetadata()['meta_comment_id'] ?? '')) {
                continue;
            }
            if ($log instanceof LeadEventLog) {
                throw new \RuntimeException('Campaign rotation belongs to another Instagram comment.');
            }
            $pending[] = [$decision, (int) ($claim['rotation'] ?? 0)];
        }

        if ([] === $pending) {
            return;
        }
        if (!$this->campaigns->dispatch($message, MetaEvents::CAMPAIGN_INSTAGRAM_COMMENT_TYPE)) {
            throw new \RuntimeException('Mautic could not evaluate the Instagram comment decision.');
        }
        foreach ($pending as [$decision, $rotation]) {
            $log = $this->eventLogs->findOneBy(['event' => $decision, 'lead' => $contact, 'rotation' => $rotation]);
            if (!$log instanceof LeadEventLog || $commentId !== (string) ($log->getMetadata()['meta_comment_id'] ?? '')) {
                throw new \RuntimeException('Mautic did not persist the Instagram comment decision.');
            }
        }
    }
}
