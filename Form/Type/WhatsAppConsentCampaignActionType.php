<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Form\Type;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

final class WhatsAppConsentCampaignActionType extends AbstractType
{
    public function __construct(
        private MetaAssetRepository $assets
    )
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $assets = [];
        foreach ($this->assets->findEnabledByType(AssetType::WhatsAppPhoneNumber) as $asset) {
            $assets[$asset->getConnection()->getName().' — '.$asset->getName()] = $asset->getId();
        }

        $builder->add('asset_id', ChoiceType::class, [
            'label'       => 'WhatsApp asset',
            'choices'     => $assets,
            'constraints' => [new NotBlank()],
        ]);
        foreach ($this->fields() as $name => $default) {
            $builder->add($name, TextType::class, [
                'label'       => str_replace('_', ' ', ucfirst($name)),
                'data'        => $options['data'][$name] ?? $default,
                'constraints' => [new NotBlank()],
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function fields(): array
    {
        return [
            'phone_field'                  => 'mobile',
            'consent_field'                => 'whatsapp_consent',
            'consent_at_field'             => 'whatsapp_consent_at',
            'business_field'               => 'whatsapp_consent_business',
            'locale_field'                 => 'whatsapp_consent_locale',
            'purpose_field'                => 'whatsapp_consent_purpose',
            'source_field'                 => 'whatsapp_consent_source',
            'consent_text_field'           => 'whatsapp_consent_text',
            'consent_version_field'        => 'whatsapp_consent_version',
            'external_submission_id_field'=> 'whatsapp_consent_submission_id',
            'page_url_field'               => 'whatsapp_consent_page_url',
        ];
    }
}
