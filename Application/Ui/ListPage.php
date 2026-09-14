<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Ui;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

final class ListPage
{
    public function paginate(QueryBuilder $query, Request $request, string $pageKey = 'page'): array
    {
        $limit = in_array($request->query->getInt('limit'), [25, 50, 100], true) ? $request->query->getInt('limit') : 25;
        $count = clone $query;
        $alias = $query->getRootAliases()[0];
        $total = (int) $count->resetDQLPart('orderBy')->select('COUNT(DISTINCT '.$alias.'.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($pages, max(1, $request->query->getInt($pageKey, 1)));
        return ['items' => $query->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'limit' => $limit, 'pageKey' => $pageKey];
    }
}
