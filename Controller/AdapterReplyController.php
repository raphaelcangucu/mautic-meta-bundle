<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use MauticPlugin\MauticMetaBundle\Application\Queue\ImmediateOutboundDispatcher;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversationRepository;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdapterReplyController
{
    public function __construct(
        private MetaConnectionRepository $connections,
        private MetaConversationRepository $conversations,
        private CredentialVault $vault,
        private OutboundQueue $queue,
        private ImmediateOutboundDispatcher $immediateDispatcher,
    ) {
    }

    public function reply(int $connectionId, string $adapterName, Request $request): JsonResponse
    {
        $connection = $this->connections->find($connectionId);
        if (!$connection instanceof MetaConnection || !$connection->isPublished()) {
            return new JsonResponse(['error' => 'Connection unavailable.'], 404);
        }

        $adapter = $this->adapter($connection, urldecode($adapterName));
        if (null === $adapter || false === ($adapter['allow_replies'] ?? false)) {
            return new JsonResponse(['error' => 'Adapter reply access disabled.'], 403);
        }

        $raw = $request->getContent();
        $timestamp = (string) $request->headers->get('X-Mautic-Meta-Timestamp');
        $signature = (string) $request->headers->get('X-Mautic-Meta-Signature');
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return new JsonResponse(['error' => 'Expired timestamp.'], 401);
        }

        $expected = 'sha256='.hash_hmac(
            'sha256',
            $timestamp.'.'.$raw,
            $this->vault->open((string) $adapter['sealed_secret']),
        );
        if (!hash_equals($expected, $signature)) {
            return new JsonResponse(['error' => 'Invalid signature.'], 401);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON.'], 400);
        }

        $conversation = $this->conversations->find((int) ($data['conversationId'] ?? 0));
        if (
            !$conversation instanceof MetaConversation
            || $conversation->getAsset()->getConnection()->getId() !== $connection->getId()
        ) {
            return new JsonResponse(['error' => 'Conversation not found.'], 404);
        }

        $text = trim((string) ($data['text'] ?? ''));
        $key = trim((string) ($data['idempotencyKey'] ?? ''));
        if ('' === $text || '' === $key || strlen($key) > 191) {
            return new JsonResponse(['error' => 'text and idempotencyKey are required.'], 400);
        }

        $operation = 'whatsapp' === $conversation->getChannel()
            ? 'whatsapp_text'
            : 'instagram_direct_message';
        $job = $this->queue->enqueue(
            $conversation->getAsset(),
            $operation,
            [
                'recipient' => $conversation->getRecipient(),
                'text'      => $text,
                '_origin'   => 'inbox_human',
                '_inbox_conversation_id' => $conversation->getId(),
            ],
            $conversation->getContact(),
            5,
            hash('sha256', $connectionId.':'.$adapterName.':'.$key),
        );
        $this->immediateDispatcher->dispatch($job);

        return new JsonResponse([
            'status'         => 'completed' === $job->getStatus() ? 'sent' : $job->getStatus(),
            'jobId'          => $job->getId(),
            'messageLogId'   => $job->getMessageLogId(),
            'conversationId' => $conversation->getId(),
        ], 'completed' === $job->getStatus() ? Response::HTTP_OK : Response::HTTP_ACCEPTED);
    }

    private function adapter(MetaConnection $connection, string $name): ?array
    {
        foreach (($connection->getSettings()['webhook_adapters'] ?? []) as $adapter) {
            if (is_array($adapter) && ($adapter['name'] ?? null) === $name && true === ($adapter['enabled'] ?? true)) {
                return $adapter;
            }
        }

        return null;
    }
}
