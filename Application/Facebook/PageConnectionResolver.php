<?php

declare(strict_types=1);
namespace MauticPlugin\MauticMetaBundle\Application\Facebook;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;

final class PageConnectionResolver
{
    public function __construct(private MetaGraphClientInterface $graph, private CredentialVault $vault) {}

    public function resolve(MetaAsset $page): MetaConnection
    {
        if (AssetType::FacebookPage !== $page->getType() || !$page->isPublished() || 'active' !== $page->getStatus()) {
            throw new \InvalidArgumentException('An active Facebook Page is required.');
        }
        $connection = $page->getConnection();
        $query = ['fields' => 'id,access_token', 'limit' => 100];
        $seen = [];
        do {
            $result = $this->graph->get($connection, 'me/accounts', $query);
            foreach ($result['data'] ?? [] as $item) {
                if ($page->getExternalId() === (string) ($item['id'] ?? '') && !empty($item['access_token'])) {
                    $resolved = clone $connection;
                    $resolved->setEncryptedAccessToken($this->vault->seal((string) $item['access_token']));
                    return $resolved;
                }
            }
            $after = (string) ($result['paging']['cursors']['after'] ?? '');
            if (empty($result['paging']['next']) || '' === $after || isset($seen[$after])) { break; }
            $seen[$after] = true; $query['after'] = $after;
        } while (true);
        throw new \DomainException('The connection does not provide an access token for this Facebook Page.');
    }
}
