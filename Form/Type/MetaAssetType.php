<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Form\Type;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

final class MetaAssetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'mautic.core.name', 'constraints' => [new NotBlank()]])
            ->add('type', ChoiceType::class, ['label' => 'mautic.meta.ui.account_type_d3cb4d', 'choices' => [
                'mautic.meta.ui.whatsapp_business_account_881154' => AssetType::WhatsAppBusinessAccount->value,
                'mautic.meta.ui.whatsapp_phone_number' => AssetType::WhatsAppPhoneNumber->value,
                'mautic.meta.ui.instagram_professional_account' => AssetType::InstagramAccount->value,
                'mautic.meta.ui.facebook_page' => AssetType::FacebookPage->value,
                'mautic.meta.ui.whatsapp_qr_session' => AssetType::WhatsAppQrSession->value,
            ]])
            ->add('external_id', TextType::class, ['label' => 'mautic.meta.ui.meta_account_id_0c0d93', 'constraints' => [new NotBlank()]])
            ->add('username', TextType::class, ['required' => false, 'label' => 'mautic.meta.ui.instagram_username_9fb7e4'])
            ->add('phone_number', TextType::class, ['required' => false, 'label' => 'mautic.meta.ui.display_phone_number_521752'])
            ->add('default_region', TextType::class, ['required' => false, 'label' => 'mautic.meta.ui.default_phone_country_095c50'])
            ->add('trusted_import_default_region', TextType::class, [
                'required' => false,
                'label' => 'mautic.meta.ui.country_for_imported_phone_numbers_968059',
                'help' => 'mautic.meta.ui.country_used_for_national_numbers_imported_through_the_trusted_ap_c4f54a',
            ])
            ->add('trusted_import_convert_legacy_br_mobile', CheckboxType::class, [
                'required' => false,
                'label' => 'mautic.meta.ui.add_the_ninth_digit_to_legacy_brazilian_mobile_numbers_0af6eb',
            ])
            ->add('contact_match_field', TextType::class, ['required' => false, 'label' => 'mautic.meta.ui.contact_field_for_exact_matching_753453', 'help' => 'mautic.meta.ui.field_alias_containing_a_whatsapp_number_or_instagram_id_whatsapp_e33abd'])
            ->add('daily_send_limit', IntegerType::class, ['required' => false, 'label' => 'mautic.meta.ui.maximum_messages_per_day_f724e8', 'help' => 'mautic.meta.ui.can_be_reduced_maximum_whatsapp_250_instagram_facebook_50_a356fb', 'constraints' => [new Positive()]])
            ->add('hourly_send_limit', IntegerType::class, ['required' => false, 'label' => 'mautic.meta.ui.maximum_messages_per_hour_3fd3bd', 'help' => 'mautic.meta.ui.maximum_whatsapp_50_instagram_facebook_20_4e962c', 'constraints' => [new Positive()]])
            ->add('recipient_daily_limit', IntegerType::class, ['required' => false, 'label' => 'mautic.meta.ui.maximum_per_recipient_per_day_14e728', 'help' => 'mautic.meta.ui.between_1_and_3_to_limit_repeated_campaign_messages_a1fa83', 'constraints' => [new Positive()]])
            ->add('recipient_cooldown_seconds', IntegerType::class, ['required' => false, 'label' => 'mautic.meta.ui.recipient_cooldown_seconds_48c5bd', 'help' => 'mautic.meta.ui.minimum_whatsapp_60_seconds_instagram_facebook_300_seconds_e6fe45', 'constraints' => [new Positive()]])
            ->add('is_default', CheckboxType::class, ['required' => false, 'label' => 'mautic.meta.ui.default_account_for_this_channel_ff7124']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
