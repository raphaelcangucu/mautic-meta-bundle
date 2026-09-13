<?php

declare(strict_types=1);
namespace MauticPlugin\MauticMetaBundle\Application\Webhook;

final class FacebookWebhookParser
{
    /** @return list<array<string,mixed>> */
    public function parse(array $payload): array
    {
        if ('page' !== ($payload['object'] ?? null)) { return []; }
        $items = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            $pageId = (string) ($entry['id'] ?? '');
            if ('' === $pageId) { continue; }
            foreach ($entry['changes'] ?? [] as $change) {
                $v = $change['value'] ?? [];
                if ('feed' !== ($change['field'] ?? '') || 'comment' !== ($v['item'] ?? '') || !in_array($v['verb'] ?? '', ['add', 'edited', 'remove'], true)) { continue; }
                $id = (string) ($v['comment_id'] ?? '');
                $author = (string) ($v['from']['id'] ?? $v['sender_id'] ?? '');
                if ('' === $id || $author === $pageId) { continue; }
                // Comment authors may be withheld by Meta; keep the comment without inventing a profile.
                $items[] = [
                    'accountId' => $pageId, 'commentId' => $id, 'mediaId' => (string) ($v['post_id'] ?? $v['parent_id'] ?? ''),
                    'parentId' => (string) ($v['parent_id'] ?? ''), 'commenterId' => $author,
                    'commenterName' => $v['from']['name'] ?? $v['sender_name'] ?? null,
                    'text' => (string) ($v['message'] ?? ''), 'type' => 'comment', 'verb' => $v['verb'],
                    'timestamp' => (int) ($v['created_time'] ?? $entry['time'] ?? time()),
                    'permalink' => $v['permalink_url'] ?? 'https://www.facebook.com/'.$id,
                    'origin_media' => $v['origin_media'] ?? [],
                ];
            }
            foreach ($entry['messaging'] ?? [] as $event) {
                if ((string) ($event['recipient']['id'] ?? '') !== $pageId) { continue; }
                $sender = (string) ($event['sender']['id'] ?? '');
                if ('' === $sender || $sender === $pageId) { continue; }
                $message = $event['message'] ?? [];
                if (!empty($message['is_echo']) || !empty($message['is_deleted'])) { continue; }
                $postback = $event['postback'] ?? [];
                $id = (string) ($message['mid'] ?? $postback['mid'] ?? '');
                if ('' === $id) { continue; }
                $items[] = ['accountId' => $pageId, 'senderId' => $sender, 'messageId' => $id,
                    'type' => $postback ? 'postback' : 'direct_message', 'verb' => 'add',
                    'timestamp' => (int) floor(((int) ($event['timestamp'] ?? time() * 1000)) / 1000),
                    'text' => (string) ($message['text'] ?? $postback['title'] ?? $postback['payload'] ?? ''),
                    'attachments' => $message['attachments'] ?? [], 'referral' => $event['referral'] ?? $postback['referral'] ?? null];
            }
        }
        return $items;
    }
}
