<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Instagram;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\Safety\OutboundPolicy;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;

final class InstagramService
{
    public function __construct(
        private MetaGraphClientInterface $graph,
        private EntityManagerInterface $entityManager,
        private IdentityManager $identities,
        private ?OutboundPolicy $outboundPolicy = null,
    ) {}

    public function profile(MetaAsset $account): array
    {
        $this->assertAccount($account);

        return $this->graph->get($account->getConnection(), $account->getExternalId(), ['fields' => 'id,user_id,username,name,profile_picture_url,followers_count,follows_count,media_count']);
    }

    public function media(MetaAsset $account, int $limit = 50, ?string $after = null): array
    {
        $this->assertAccount($account);
        $query = ['fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,timestamp,permalink,like_count,comments_count', 'limit' => max(1, min(100, $limit))];
        if (null !== $after && '' !== $after) {
            $query['after'] = $after;
        }

        return $this->graph->get($account->getConnection(), $account->getExternalId().'/media', $query);
    }

    public function comments(MetaAsset $account, string $mediaId, int $limit = 50, ?string $after = null): array
    {
        $this->assertAccount($account);
        $query = ['fields' => 'id,text,timestamp,from,replies{from,text,timestamp}', 'order' => 'reverse_chronological', 'limit' => max(1, min(100, $limit))];
        if (null !== $after && '' !== $after) {
            $query['after'] = $after;
        }

        return $this->graph->get($account->getConnection(), $mediaId.'/comments', $query);
    }

    public function insights(MetaAsset $account, string $mediaId, array $metrics): array
    {
        $this->assertAccount($account);
        $metrics = array_values(array_intersect($metrics, ['views', 'reach', 'saved', 'shares', 'likes', 'comments', 'total_interactions', 'follows', 'profile_activity']));
        if ([] === $metrics) {
            throw new \InvalidArgumentException('At least one supported Instagram insight metric is required.');
        }

        return $this->graph->get($account->getConnection(), $mediaId.'/insights', ['metric' => implode(',', $metrics)]);
    }

    public function privateReply(MetaAsset $account, string $commentId, string $text, ?Lead $contact = null): MetaMessage
    {
        return $this->send($account, $commentId, 'private_reply', [
            'recipient' => ['comment_id' => $commentId], 'message' => ['text' => $this->text($text, 1000)],
        ], $account->getExternalId().'/messages', $contact);
    }

    public function directMessage(MetaAsset $account, string $instagramUserId, string $text, ?Lead $contact = null): MetaMessage
    {
        return $this->send($account, $instagramUserId, 'direct_message', [
            'recipient' => ['id' => $instagramUserId], 'message' => ['text' => $this->text($text, 1000)],
        ], $account->getExternalId().'/messages', $contact);
    }

    public function publicReply(MetaAsset $account, string $commentId, string $text, ?Lead $contact = null): MetaMessage
    {
        return $this->send($account, $commentId, 'comment_reply', ['message' => $this->text($text, 2200)], $commentId.'/replies', $contact);
    }

    public function conversations(MetaAsset $account, int $limit = 50, ?string $after = null): array
    {
        $this->assertAccount($account);
        $query = ['platform' => 'instagram', 'fields' => 'participants,updated_time,messages.limit(1){message,from,created_time}', 'limit' => max(1, min(100, $limit))];
        if (null !== $after && '' !== $after) {
            $query['after'] = $after;
        }

        return $this->graph->get($account->getConnection(), $account->getExternalId().'/conversations', $query);
    }

    public function conversationMessages(MetaAsset $account, string $conversationId): array
    {
        $this->assertAccount($account);

        return $this->graph->get($account->getConnection(), $conversationId, ['fields' => 'messages{id,created_time,from,to,message,attachments}']);
    }

    private function send(MetaAsset $account, string $recipient, string $type, array $payload, string $path, ?Lead $contact): MetaMessage
    {
        $this->assertAccount($account);
        $this->identities->assertChannelContactable($contact, 'instagram');
        $this->outboundPolicy?->assertAllowed($account, 'instagram', $recipient, $type);
        $log = (new MetaMessage())->setAsset($account)->setContact($contact)->setChannel('instagram')->setMessageType($type)->setRecipient($recipient)->setPayload($payload);
        $this->entityManager->persist($log);
        $this->entityManager->flush();
        try {
            $response = $this->graph->post($account->getConnection(), $path, $payload);
            $messageId = (string) ($response['message_id'] ?? $response['id'] ?? '');
            if ('' === $messageId) {
                throw new \RuntimeException('Meta response did not contain an Instagram message ID.');
            }
            $log->setExternalId($messageId)->setResponse($response)->setStatus('accepted');
            $this->entityManager->flush();

            return $log;
        } catch (\Throwable $exception) {
            $log->setError($exception->getMessage())->setStatus('failed');
            $this->entityManager->flush();
            throw $exception;
        }
    }

    private function assertAccount(MetaAsset $asset): void
    {
        if (AssetType::InstagramAccount !== $asset->getType() || !$asset->isPublished() || 'active' !== $asset->getStatus()) {
            throw new \InvalidArgumentException('A published, active Instagram professional-account asset is required.');
        }
    }

    private function text(string $text, int $limit): string
    {
        $text = trim($text);
        if ('' === $text) {
            throw new \InvalidArgumentException('Instagram message cannot be empty.');
        }

        return mb_substr($text, 0, $limit);
    }
}
