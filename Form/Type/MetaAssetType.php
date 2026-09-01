<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Form\Type;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

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
            ->add('contact_match_field', TextType::class, ['required' => false, 'label' => 'Contact field for exact identity matching', 'help' => 'Optional field alias containing the WhatsApp number or Instagram user ID. WhatsApp falls back to a unique phone/mobile match.'])
            ->add('require_opt_in', CheckboxType::class, ['required' => false, 'label' => 'Require explicit WhatsApp opt-in before sending'])
            ->add('is_default', CheckboxType::class, ['required' => false, 'label' => 'Default asset for this channel']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
