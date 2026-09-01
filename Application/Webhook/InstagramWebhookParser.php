<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Webhook;

final class InstagramWebhookParser
{
    /**
     * @return array{comments:list<array>,messages:list<array>,postbacks:list<array>}
     */
    public function parse(array $payload): array
    {
        $comments = [];
        $messages = [];
        $postbacks = [];
        if ('instagram' !== ($payload['object'] ?? null)) {
            return compact('comments', 'messages', 'postbacks');
        }
        foreach ($payload['entry'] ?? [] as $entry) {
            $accountId = (string) ($entry['id'] ?? '');
            foreach ($entry['changes'] ?? [] as $change) {
                if ('comments' !== ($change['field'] ?? null)) {
                    continue;
                }
                $value = $change['value'] ?? [];
                $commentId = (string) ($value['id'] ?? $value['comment_id'] ?? '');
                $mediaId = (string) ($value['media']['id'] ?? $value['media_id'] ?? '');
                $commenterId = (string) ($value['from']['id'] ?? '');
                if ('' !== $accountId && '' !== $commentId && '' !== $mediaId && '' !== $commenterId && $commenterId !== $accountId) {
                    $comments[] = ['accountId' => $accountId, 'commentId' => $commentId, 'mediaId' => $mediaId, 'originalMediaId' => $value['media']['original_media_id'] ?? null, 'commenterId' => $commenterId, 'commenterName' => $value['from']['username'] ?? null, 'text' => (string) ($value['text'] ?? '')];
                }
            }
            foreach ($entry['messaging'] ?? [] as $messaging) {
                $senderId = (string) ($messaging['sender']['id'] ?? '');
                $resolvedAccountId = '' !== $accountId ? $accountId : (string) ($messaging['recipient']['id'] ?? '');
                $message = $messaging['message'] ?? null;
                if (is_array($message) && empty($message['is_echo']) && empty($message['is_deleted']) && empty($message['is_unsupported'])) {
                    $text = trim((string) ($message['text'] ?? ''));
                    $messageId = (string) ($message['mid'] ?? '');
                    if ('' !== $text && '' !== $messageId && '' !== $senderId && $senderId !== $resolvedAccountId) {
                        $messages[] = ['accountId' => $resolvedAccountId, 'senderId' => $senderId, 'messageId' => $messageId, 'text' => $text];
                    }
                }
                $payloadValue = $messaging['postback']['payload'] ?? null;
                if (is_string($payloadValue) && '' !== $payloadValue && '' !== $senderId && $senderId !== $resolvedAccountId) {
                    $postbacks[] = ['accountId' => $resolvedAccountId, 'senderId' => $senderId, 'payload' => $payloadValue, 'messageId' => $messaging['postback']['mid'] ?? null];
                }
            }
        }

        return compact('comments', 'messages', 'postbacks');
    }
}
