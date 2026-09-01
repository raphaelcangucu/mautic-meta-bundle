<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticMetaBundle\Application\Queue\QueueManager;
use MauticPlugin\MauticMetaBundle\Application\Webhook\WebhookReplay;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJobRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaWebhookEvent;
use MauticPlugin\MauticMetaBundle\Entity\MetaWebhookEventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OperationsController extends AbstractController
{
    public function index(CorePermissions $permissions, MetaOutboundJobRepository $jobs, MetaMessageRepository $messages, MetaWebhookEventRepository $events): Response
    {
        if (!$permissions->isGranted('meta:messages:view') || !$permissions->isGranted('meta:webhooks:view')) { throw $this->createAccessDeniedException(); }

        return $this->render('@MauticMeta/Operations/index.html.twig', [
            'jobs' => $jobs->findBy([], ['dateAdded' => 'DESC'], 100),
            'messages' => $messages->findBy([], ['dateAdded' => 'DESC'], 100),
            'events' => $events->findBy([], ['receivedAt' => 'DESC'], 100),
        ]);
    }

    public function retryJob(int $jobId, Request $request, CorePermissions $permissions, MetaOutboundJobRepository $jobs, QueueManager $manager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:messages:edit') || !$this->isCsrfTokenValid('meta_job_retry_'.$jobId, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $job = $jobs->find($jobId);
        if (!$job instanceof MetaOutboundJob) { throw $this->createNotFoundException(); }
        try { $manager->retry($job); $this->addFlash('notice', 'Meta job queued for retry.'); } catch (\DomainException $exception) { $this->addFlash('error', $exception->getMessage()); }

        return $this->redirectToRoute('mautic_meta_operations');
    }

    public function cancelJob(int $jobId, Request $request, CorePermissions $permissions, MetaOutboundJobRepository $jobs, QueueManager $manager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:messages:edit') || !$this->isCsrfTokenValid('meta_job_cancel_'.$jobId, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $job = $jobs->find($jobId);
        if (!$job instanceof MetaOutboundJob) { throw $this->createNotFoundException(); }
        try { $manager->cancel($job); $this->addFlash('notice', 'Meta job cancelled.'); } catch (\DomainException $exception) { $this->addFlash('error', $exception->getMessage()); }

        return $this->redirectToRoute('mautic_meta_operations');
    }

    public function replayWebhook(int $eventId, Request $request, CorePermissions $permissions, MetaWebhookEventRepository $events, WebhookReplay $replay): RedirectResponse
    {
        if (!$permissions->isGranted('meta:webhooks:edit') || !$this->isCsrfTokenValid('meta_webhook_replay_'.$eventId, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $event = $events->find($eventId);
        if (!$event instanceof MetaWebhookEvent) { throw $this->createNotFoundException(); }
        try { $replay->replay($event); $this->addFlash('notice', 'Webhook event replayed successfully.'); } catch (\Throwable $exception) { $this->addFlash('error', 'Webhook replay failed: '.$exception->getMessage()); }

        return $this->redirectToRoute('mautic_meta_operations');
    }
}
