<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Controller;

use Symfony\Component\HttpFoundation\Response;

trait MetaViewTrait
{
    private function metaView(string $template, array $parameters = []): Response
    {
        $request = $this->getCurrentRequest();
        $query = array_intersect_key($request->query->all(), array_flip(['page','jobs_page','messages_page','events_page','deliveries_page','search','asset','connection','channel','type','status','language','category','operation','from','to','period','linked','consent','limit','tab']));
        $parameters['listQuery'] = $query;
        $route = $request->getBaseUrl().$request->getPathInfo().($query ? '?'.http_build_query($query) : '');
        return $this->delegateView(['contentTemplate' => $template, 'viewParameters' => $parameters, 'passthroughVars' => ['mauticContent' => 'meta', 'route' => $route]]);
    }
}
