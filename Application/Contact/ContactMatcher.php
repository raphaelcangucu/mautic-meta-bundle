<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Contact;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Domain\UnresolvedRecipient;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;

final class ContactMatcher
{
    public function __construct(
        private LeadModel $leadModel,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function match(MetaAsset $asset, string $externalId): ?Lead
    {
        $externalId = trim($externalId);
        if ('' === $externalId) {
            return null;
        }

        // Customer identities retain their explicitly linked contact. Never match
        // a new customer's inbound number against the shared CRM address book.
        if ($asset->getConnection()->isCustomer()) {
            return null;
        }

        $field = trim((string) ($asset->getSettings()['contact_match_field'] ?? ''));
        if ('' !== $field) {
            if (1 !== preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $field)) {
                return null;
            }

            $contacts = $this->leadModel->getRepository()->getLeadsByFieldValue($field, $externalId);

            return 1 === count($contacts) && $contacts[0] instanceof Lead ? $contacts[0] : null;
        }

        // Os dois tipos cujo identificador de remetente E o telefone do cliente. O id de
        // WABA fica de fora de proposito: tambem e feito de digitos, e nao e numero de
        // ninguem. Nao se trata de qual canal e oficial -- e a mesma pessoa, o mesmo
        // numero, e uma conversa sem contato ligado nao tem historico, campos nem campanha.
        if (!in_array($asset->getType(), [AssetType::WhatsAppPhoneNumber, AssetType::WhatsAppQrSession], true)) {
            return null;
        }

        // Um destinatario marcado chegou sem telefone. Os digitos que sobram dele parecem
        // numero e casariam contato por sufixo: seria o contato errado, calado.
        if (UnresolvedRecipient::marks($externalId)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $externalId) ?? '';
        if (strlen($digits) < 8) {
            return null;
        }

        // WhatsApp normally supplies an E.164 number. Comparing the last digits
        // tolerates locally formatted CRM values while refusing ambiguous matches.
        $suffix = substr($digits, -min(11, strlen($digits)));
        $tablePrefix = defined('MAUTIC_TABLE_PREFIX') ? (string) constant('MAUTIC_TABLE_PREFIX') : '';
        $sql = sprintf(
            "SELECT id FROM %sleads WHERE RIGHT(REGEXP_REPLACE(COALESCE(mobile, ''), '[^0-9]', ''), :length) = :suffix OR RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), :length) = :suffix LIMIT 2",
            $tablePrefix,
        );
        $ids = $this->entityManager->getConnection()->fetchFirstColumn($sql, [
            'length' => strlen($suffix),
            'suffix' => $suffix,
        ]);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (1 !== count($ids)) {
            return null;
        }

        $contact = $this->entityManager->find(Lead::class, $ids[0]);

        return $contact instanceof Lead ? $contact : null;
    }
}
