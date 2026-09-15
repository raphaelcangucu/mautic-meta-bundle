<?php

declare(strict_types=1);
namespace MauticPlugin\MauticMetaBundle\Mcp\Application;
use MauticPlugin\MauticMetaBundle\Application\Facebook\PageConnectionResolver;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;

final class FacebookReadService
{
    public function __construct(private MetaGraphClientInterface $graph, private PageConnectionResolver $connections) {}
    public function read(string $action,MetaAsset $page,?string $resourceId,int $limit,?string $after): array
    {
        $connection=$this->connections->resolve($page);
        $query=['limit'=>max(1,min(100,$limit))];
        if($after!==null)$query['after']=mb_substr($after,0,2000);
        switch($action){
            case 'facebook_profile':$path=$page->getExternalId();$query=['fields'=>'id,name,link,picture'];break;
            case 'facebook_posts':$path=$page->getExternalId().'/posts';$query['fields']='id,message,created_time,permalink_url,full_picture';break;
            case 'facebook_reels':$path=$page->getExternalId().'/video_reels';$query['fields']='id,description,created_time,permalink_url';break;
            case 'facebook_comments':
                if(!$resourceId||!preg_match('/^[0-9]+(?:_[0-9]+)?$/',$resourceId))throw new \InvalidArgumentException('A Facebook post or reel ID is required.');
                $owner=$this->graph->get($connection,$resourceId,['fields'=>'id,from']);
                if((string)($owner['from']['id']??'')!==$page->getExternalId())throw new \DomainException('The post or reel does not belong to this Page.');
                $path=$resourceId.'/comments';$query['fields']='id,message,created_time,from,permalink_url,attachment';break;
            default:throw new \InvalidArgumentException('Unsupported Facebook read.');
        }
        $result=$this->graph->get($connection,$path,$query);
        // Paging URLs can embed credentials; expose only opaque cursors.
        if(isset($result['paging']))$result['paging']=['cursors'=>$result['paging']['cursors']??[],'hasMore'=>!empty($result['paging']['next'])];
        return $result;
    }
}
