<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

class MetaConversationRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'mc';
    }
}
