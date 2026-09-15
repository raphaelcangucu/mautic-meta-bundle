<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticMetaBundle\Application\Queue\QueueManager;
use MauticPlugin\MauticMetaBundle\Application\Webhook\WebhookReplay;
use MauticPlugin\MauticMetaBundle\Entity\MetaAdapterDelivery;
use MauticPlugin\MauticMetaBundle\Entity\MetaAdapterDeliveryRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJobRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaWebhookEvent;
use MauticPlugin\MauticMetaBundle\Entity\MetaWebhookEventRepository;
use Mautic\CoreBundle\Controller\CommonController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OperationsController extends CommonController
{
    use MetaViewTrait;

    public function index(CorePermissions $permissions, MetaOutboundJobRepository $jobs, MetaMessageRepository $messages, MetaWebhookEventRepository $events, MetaAdapterDeliveryRepository $deliveries, Request $request, \MauticPlugin\MauticMetaBundle\Application\Ui\ListPage $paging, \MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository $assets): Response
    {
        if (!$permissions->isGranted('meta:messages:view') || !$permissions->isGranted('meta:webhooks:view')) {
            throw $this->createAccessDeniedException();
        }

        $tab = $request->query->getString('tab', 'jobs');
        if (!in_array($tab, ['jobs', 'messages', 'events', 'deliveries'], true)) { $tab = 'jobs'; }
        $repository = match ($tab) { 'messages' => $messages, 'events' => $events, 'deliveries' => $deliveries, default => $jobs };
        $dateField = 'events' === $tab ? 'receivedAt' : 'dateAdded';
        $query = $repository->createQueryBuilder('r')->orderBy('r.'.$dateField, 'DESC')->addOrderBy('r.id', 'DESC');
        if ($status = $request->query->getString('status')) {
            if ('queued' === $status && 'jobs' === $tab) { $query->andWhere('r.status IN (:statuses)')->setParameter('statuses', ['pending', 'retry']); }
            else { $query->andWhere('r.status = :status')->setParameter('status', $status); }
        }
        if (in_array($tab, ['jobs', 'messages'], true)) {
            $query->join('r.asset', 'a')->addSelect('a');
            if ($asset = (int) $request->query->getString('asset')) { $query->andWhere('a.id = :asset')->setParameter('asset', $asset); }
            if ($channel = $request->query->getString('channel')) {
                $type = ['whatsapp' => 'whatsapp_phone_number', 'instagram' => 'instagram_account', 'facebook' => 'facebook_page'][$channel] ?? '';
                $query->andWhere('a.type = :type')->setParameter('type', $type);
            }
            if ($operation = $request->query->getString('operation')) { $query->andWhere('r.'.('jobs' === $tab ? 'operation' : 'messageType').' = :operation')->setParameter('operation', $operation); }
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            $value = $request->query->getString($key);
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date && $date->format('Y-m-d') === $value) {
                $query->andWhere('r.'.$dateField.' '.$operator.' :'.$key)->setParameter($key, 'to' === $key ? $date->modify('+1 day') : $date);
            }
        }
        return $this->metaView('@MauticMeta/Operations/index.html.twig', ['tab' => $tab, 'listing' => $paging->paginate($query, $request, $tab.'_page'), 'allAssets' => $assets->findBy([], ['name' => 'ASC'])]);

    }

    public function retryAdapter(int $deliveryId, Request $request, CorePermissions $permissions, MetaAdapterDeliveryRepository $deliveries, EntityManagerInterface $entityManager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:webhooks:edit') || !$this->isCsrfTokenValid('meta_adapter_retry_'.$deliveryId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $delivery = $deliveries->find($deliveryId);
        if (!$delivery instanceof MetaAdapterDelivery) {
            throw $this->createNotFoundException();
        }
        if ('failed' !== $delivery->getStatus()) {
            $this->addFlash('error', $this->translator->trans('mautic.meta.ui.only_failed_adapter_deliveries_can_be_retried'));
        } else {
            $delivery->setStatus('pending')->setAttempts(0)->setAvailableAt(new \DateTimeImmutable())->setCompletedAt(null)->setLastError(null);
            $entityManager->persist($delivery);
            $entityManager->flush();
            $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.adapter_delivery_queued_for_retry'));
        }

        return $this->redirectToRoute('mautic_meta_operations');
    }

    public function retryJob(int $jobId, Request $request, CorePermissions $permissions, MetaOutboundJobRepository $jobs, QueueManager $manager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:messages:edit') || !$this->isCsrfTokenValid('meta_job_retry_'.$jobId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $job = $jobs->find($jobId);
        if (!$job instanceof MetaOutboundJob) {
            throw $this->createNotFoundException();
        }
        try {
            $manager->retry($job);
            $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.meta_job_queued_for_retry'));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('mautic_meta_operations');
    }

    public function cancelJob(int $jobId, Request $request, CorePermissions $permissions, MetaOutboundJobRepository $jobs, QueueManager $manager): RedirectResponse
    {
        if (!$permissions->isGranted('meta:messages:edit') || !$this->isCsrfTokenValid('meta_job_cancel_'.$jobId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $job = $jobs->find($jobId);
        if (!$job instanceof MetaOutboundJob) {
            throw $this->createNotFoundException();
        }
        try {
            $manager->cancel($job);
            $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.meta_job_cancelled'));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('mautic_meta_operations');
    }

    public function replayWebhook(int $eventId, Request $request, CorePermissions $permissions, MetaWebhookEventRepository $events, WebhookReplay $replay): RedirectResponse
    {
        if (!$permissions->isGranted('meta:webhooks:edit') || !$this->isCsrfTokenValid('meta_webhook_replay_'.$eventId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $event = $events->find($eventId);
        if (!$event instanceof MetaWebhookEvent) {
            throw $this->createNotFoundException();
        }
        try {
            $replay->replay($event);
            $this->addFlash('notice', $this->translator->trans('mautic.meta.ui.webhook_event_replayed_successfully'));
        } catch (\Throwable $exception) {
            $this->addFlash('error', $this->translator->trans('mautic.meta.ui.replay_failed', ['%error%' => $exception->getMessage()]));
        }

        return $this->redirectToRoute('mautic_meta_operations');
    }
}
