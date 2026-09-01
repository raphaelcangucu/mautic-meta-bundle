<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentityRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJobRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaWebhookEventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class DashboardController extends AbstractController
{
    public function index(CorePermissions $permissions, MetaConnectionRepository $connections, MetaAssetRepository $assets, MetaWebhookEventRepository $events, MetaMessageRepository $messages, MetaContactIdentityRepository $identities, MetaOutboundJobRepository $jobs): Response
    {
        if (!$permissions->isGranted('meta:connections:view')) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('@MauticMeta/Dashboard/index.html.twig', [
            'connectionCount' => $connections->count([]),
            'assetCount'      => $assets->count([]),
            'eventCount'      => $events->count([]),
            'webhookFailures' => $events->count(['status' => 'failed']),
            'messageCount' => $messages->count([]),
            'identityCount' => $identities->count([]),
            'queuePending' => $jobs->count(['status' => 'pending']) + $jobs->count(['status' => 'retry']),
            'queueFailed' => $jobs->count(['status' => 'failed']),
        ]);
    }
}
