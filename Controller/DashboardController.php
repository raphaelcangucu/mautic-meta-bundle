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
use Mautic\CoreBundle\Controller\CommonController;
use Symfony\Component\HttpFoundation\Response;

final class DashboardController extends CommonController
{
    use MetaViewTrait;

    public function index(CorePermissions $permissions, MetaConnectionRepository $connections, MetaAssetRepository $assets, MetaWebhookEventRepository $events, MetaMessageRepository $messages, MetaContactIdentityRepository $identities, MetaOutboundJobRepository $jobs, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        if (!$permissions->isGranted('meta:connections:view')) {
            throw $this->createAccessDeniedException();
        }

        $assetId = (int) $request->query->getString('asset');
        $period = $request->query->getString('period', 'all');
        $days = ['7' => 7, '30' => 30][$period] ?? null;
        $countActivity = static function ($repository, ?array $statuses = null) use ($assetId, $days): int {
            $q = $repository->createQueryBuilder('r')->select('COUNT(r.id)');
            if ($assetId > 0) { $q->andWhere('IDENTITY(r.asset) = :asset')->setParameter('asset', $assetId); }
            if ($days) { $q->andWhere('r.dateAdded >= :since')->setParameter('since', new \DateTimeImmutable('today -'.$days.' days')); }
            if ($statuses) { $q->andWhere('r.status IN (:statuses)')->setParameter('statuses', $statuses); }
            return (int) $q->getQuery()->getSingleScalarResult();
        };
        return $this->metaView('@MauticMeta/Dashboard/index.html.twig', [
            'connectionCount' => $connections->count([]),
            'assetCount'      => $assets->count([]),
            'eventCount'      => $events->count([]),
            'webhookFailures' => $events->count(['status' => 'failed']),
            'allAssets' => $assets->findBy([], ['name' => 'ASC']),
            'messageCount' => $countActivity($messages),
            'identityCount' => $identities->count([]),
            'queuePending' => $countActivity($jobs, ['pending', 'retry']),
            'queueFailed' => $countActivity($jobs, ['failed']),
        ]);
    }
}
