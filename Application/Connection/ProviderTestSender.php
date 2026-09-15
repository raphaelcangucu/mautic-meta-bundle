<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate;

final class ProviderTestSender
{
    public function __construct(private WabaResolver $resolver, private IdentityManager $identities, private OutboundQueue $queue, private PhoneNormalizer $normalizer, private \MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentityRepository $identityRepository)
    {
    }

    public function enqueue(MetaAsset $phone, WhatsAppTemplate $template, string $recipient, array $components, string $attempt): MetaOutboundJob
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $attempt)) {
            throw new \DomainException('Tentativa inválida.');
        }
        $waba = $this->resolver->forPhone($phone);
        if ($template->getBusinessAccount()->getId() !== $waba->getId() || 'APPROVED' !== $template->getStatus()) {
            throw new \DomainException('Selecione um template aprovado do WABA deste número.');
        }
        if (!$phone->isPublished() || 'active' !== $phone->getStatus() || !$phone->getConnection()->isPublished() || 'active' !== $phone->getConnection()->getStatus()) {
            throw new \DomainException('Conclua o diagnóstico e a autorização antes do teste.');
        }
        $recipient = $this->normalizer->normalize($recipient, (string) ($phone->getSettings()['default_region'] ?? 'BR'));
        // Consent is asset-specific and cannot be created or reset by a test send.
        $identity = $this->identityRepository->findForAssetAndExternalId($phone, $recipient);
        if (!$identity || \MauticPlugin\MauticMetaBundle\Domain\ConsentStatus::OptedIn !== $identity->getConsentStatus()) {
            throw new \DomainException('Registre o consentimento deste destinatário para este remetente antes do teste.');
        }
        $contact = $identity->getContact();
        $this->identities->assertCanSend($phone, $recipient, $contact);

        return $this->queue->enqueue($phone, 'whatsapp_template', ['recipient' => $recipient, 'name' => $template->getName(), 'language' => $template->getLanguage(), 'components' => $components, '_template_id' => $template->getId(), '_origin' => 'provider_test'], $contact, 1, 'provider_test_'.$attempt);
    }
}
