<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversationRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConversationController extends AbstractController
{
    public function index(
        ?int $conversationId,
        Request $request,
        CorePermissions $permissions,
        MetaConversationRepository $conversations,
        MetaMessageRepository $messages,
        ConversationManager $manager,
    ): Response {
        if (!$permissions->isGranted('meta:messages:view')) {
            throw $this->createAccessDeniedException();
        }

        $criteria = [];
        $status = trim((string) $request->query->get('status', ''));
        $channel = trim((string) $request->query->get('channel', ''));
        if (in_array($status, ['open', 'pending', 'resolved', 'archived'], true)) {
            $criteria['status'] = $status;
        }

        if (in_array($channel, ['whatsapp', 'instagram'], true)) {
            $criteria['channel'] = $channel;
        }

        $items = $conversations->findBy($criteria, ['lastMessageAt' => 'DESC'], 100);
        $selected = null;
        $history = [];
        if ($conversationId) {
            $selected = $conversations->find($conversationId);
            if (!$selected instanceof MetaConversation) {
                throw $this->createNotFoundException();
            }

            $history = $messages->findBy(
                ['conversation' => $selected],
                ['dateAdded' => 'ASC'],
                500,
            );
            $manager->markRead($selected);
        }

        return $this->render('@MauticMeta/Conversation/index.html.twig', [
            'conversations'  => $items,
            'selected'       => $selected,
            'messages'       => $history,
            'statusFilter'   => $status,
            'channelFilter'  => $channel,
        ]);
    }

    public function reply(
        int $conversationId,
        Request $request,
        CorePermissions $permissions,
        MetaConversationRepository $conversations,
        OutboundQueue $queue,
    ): RedirectResponse {
        if (!$permissions->isGranted('meta:messages:create') || !$this->isCsrfTokenValid('meta_conversation_reply_'.$conversationId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $conversation = $conversations->find($conversationId);
        if (!$conversation instanceof MetaConversation) {
            throw $this->createNotFoundException();
        }

        $text = trim((string) $request->request->get('text'));
        if ('' === $text) {
            $this->addFlash('error', 'Message cannot be empty.');

            return $this->redirectToRoute('mautic_meta_conversation_view', ['conversationId' => $conversationId]);
        }

        $operation = 'whatsapp' === $conversation->getChannel()
            ? 'whatsapp_text'
            : 'instagram_direct_message';
        $job = $queue->enqueue(
            $conversation->getAsset(),
            $operation,
            [
                'recipient' => $conversation->getRecipient(),
                'text'      => $text,
            ],
            $conversation->getContact(),
        );
        $this->addFlash('notice', 'Reply queued as job #'.$job->getId().'.');

        return $this->redirectToRoute('mautic_meta_conversation_view', ['conversationId' => $conversationId], Response::HTTP_SEE_OTHER);
    }

    public function status(
        int $conversationId,
        Request $request,
        CorePermissions $permissions,
        MetaConversationRepository $conversations,
        ConversationManager $manager,
    ): RedirectResponse {
        if (!$permissions->isGranted('meta:messages:edit') || !$this->isCsrfTokenValid('meta_conversation_status_'.$conversationId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $conversation = $conversations->find($conversationId);
        if (!$conversation instanceof MetaConversation) {
            throw $this->createNotFoundException();
        }

        try {
            $manager->setStatus($conversation, (string) $request->request->get('status'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute(
            'mautic_meta_conversation_view',
            ['conversationId' => $conversationId],
            Response::HTTP_SEE_OTHER,
        );
    }
}
