<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WhatsAppTemplate>
 */
class WhatsAppTemplateRepository extends CommonRepository
{
    /**
     * @return list<WhatsAppTemplate>
     */
    public function findApprovedForAsset(MetaAsset $asset): array
    {
        return $this->findBy(['businessAccount' => $asset, 'status' => 'APPROVED'], ['name' => 'ASC', 'language' => 'ASC']);
    }

    public function getTableAlias(): string { return 'wat'; }
}
