<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Instagram;

use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;

final class InstagramPageConnectionResolver
{
    /** @var array<int, string> */
    private array $pageIds = [];

    public function __construct(
        private MetaGraphClientInterface $graph,
        private InstagramAccountResolver $accounts,
        private CredentialVault $vault,
    ) {
    }

    public function resolve(MetaAsset $account): MetaConnection
    {
        $connection = $account->getConnection();
        $canonicalId = $this->accounts->resolve($account);
        $query = ['fields' => 'id,access_token,instagram_business_account{id},connected_instagram_account{id}', 'limit' => 100];
        $seenCursors = [];
        do {
            $pages = $this->graph->get($connection, 'me/accounts', $query);
            foreach ((array) ($pages['data'] ?? []) as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $matches = false;
                foreach (['instagram_business_account', 'connected_instagram_account'] as $relationship) {
                    $matches = $matches || $canonicalId === (string) ($page[$relationship]['id'] ?? '');
                }
                if (!$matches) {
                    continue;
                }
                $pageId = trim((string) ($page['id'] ?? ''));
                if ('' === $pageId) {
                    throw new \RuntimeException('The linked Facebook Page did not provide its ID.');
                }
                $token = trim((string) ($page['access_token'] ?? ''));
                if ('' === $token) {
                    throw new \RuntimeException('The linked Facebook Page did not provide a messaging access token.');
                }
                // Keep the shared System User credential intact; the Page credential stays in memory.
                $pageConnection = clone $connection;
                $pageConnection->setEncryptedAccessToken($this->vault->seal($token));
                $this->pageIds[spl_object_id($account)] = $pageId;

                return $pageConnection;
            }
            $after = (string) ($pages['paging']['cursors']['after'] ?? '');
            if (empty($pages['paging']['next']) || '' === $after || isset($seenCursors[$after])) {
                break;
            }
            $seenCursors[$after] = true;
            $query['after'] = $after;
        } while (true);

        throw new \RuntimeException('No accessible Facebook Page is linked to this Instagram account for messaging.');
    }

    public function pageId(MetaAsset $account): string
    {
        if (!isset($this->pageIds[spl_object_id($account)])) {
            $this->resolve($account);
        }

        return $this->pageIds[spl_object_id($account)];
    }
}
