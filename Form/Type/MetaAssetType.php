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
            ->add('type', ChoiceType::class, ['label' => 'Asset type', 'choices' => [
                'WhatsApp Business Account' => AssetType::WhatsAppBusinessAccount->value,
                'WhatsApp phone number' => AssetType::WhatsAppPhoneNumber->value,
                'Instagram professional account' => AssetType::InstagramAccount->value,
                'Facebook Page' => AssetType::FacebookPage->value,
            ]])
            ->add('external_id', TextType::class, ['label' => 'Meta asset ID', 'constraints' => [new NotBlank()]])
            ->add('username', TextType::class, ['required' => false, 'label' => 'Instagram username'])
            ->add('phone_number', TextType::class, ['required' => false, 'label' => 'Display phone number'])
            ->add('default_region', TextType::class, ['required' => false, 'label' => 'Default phone region'])
            ->add('trusted_import_default_region', TextType::class, [
                'required' => false,
                'label' => 'Waitlist/API phone region',
                'help' => 'Region used only for national phone numbers imported by the trusted API, for example BR.',
            ])
            ->add('trusted_import_convert_legacy_br_mobile', CheckboxType::class, [
                'required' => false,
                'label' => 'Convert legacy Brazilian mobile numbers by adding the ninth digit',
            ])
            ->add('contact_match_field', TextType::class, ['required' => false, 'label' => 'Contact field for exact identity matching', 'help' => 'Optional field alias containing the WhatsApp number or Instagram user ID. WhatsApp falls back to a unique phone/mobile match.'])
            ->add('require_opt_in', CheckboxType::class, ['required' => false, 'label' => 'Require explicit WhatsApp opt-in before sending'])
            ->add('daily_send_limit', IntegerType::class, ['required' => false, 'label' => 'Maximum messages per 24 hours', 'help' => 'May be lowered. Safety ceiling: WhatsApp 250; Instagram/Facebook 50.', 'constraints' => [new Positive()]])
            ->add('hourly_send_limit', IntegerType::class, ['required' => false, 'label' => 'Maximum messages per hour', 'help' => 'Safety ceiling: WhatsApp 50; Instagram/Facebook 20.', 'constraints' => [new Positive()]])
            ->add('recipient_daily_limit', IntegerType::class, ['required' => false, 'label' => 'Maximum messages per recipient per 24 hours', 'help' => 'Between 1 and 3. This prevents repeated campaign contact.', 'constraints' => [new Positive()]])
            ->add('recipient_cooldown_seconds', IntegerType::class, ['required' => false, 'label' => 'Minimum seconds between messages to one recipient', 'help' => 'Minimum: WhatsApp 60 seconds; Instagram/Facebook 300 seconds.', 'constraints' => [new Positive()]])
            ->add('is_default', CheckboxType::class, ['required' => false, 'label' => 'Default asset for this channel']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
