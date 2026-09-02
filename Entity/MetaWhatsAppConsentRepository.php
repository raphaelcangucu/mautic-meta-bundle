<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MetaWhatsAppConsent>
 */
final class MetaWhatsAppConsentRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'mwc';
    }

    public function findSubmission(MetaAsset $asset, string $submissionId): ?MetaWhatsAppConsent
    {
        $event = $this->findOneBy(['asset' => $asset, 'externalSubmissionId' => $submissionId]);

        return $event instanceof MetaWhatsAppConsent ? $event : null;
    }
}
