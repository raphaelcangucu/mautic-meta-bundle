<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class WhatsAppTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $editing = (bool) $options['editing'];
        $builder
            ->add('business_account_id', ChoiceType::class, ['label' => 'mautic.meta.ui.whatsapp_business_account_881154', 'choices' => $options['business_accounts'], 'disabled' => $editing, 'constraints' => [new NotBlank()]])
            ->add('name', TextType::class, ['label' => 'mautic.meta.ui.template_name_077fcb', 'disabled' => $editing, 'constraints' => [new NotBlank()]])
            ->add('language', TextType::class, ['label' => 'mautic.meta.ui.language_009433', 'disabled' => $editing, 'constraints' => [new NotBlank()]])
            ->add('category', ChoiceType::class, ['label' => 'mautic.meta.ui.category_5966b0', 'choices' => ['mautic.meta.ui.marketing' => 'MARKETING', 'mautic.meta.ui.utility' => 'UTILITY', 'mautic.meta.ui.authentication' => 'AUTHENTICATION']])
            ->add('components_json', TextareaType::class, ['label' => 'mautic.meta.ui.components_json_ec088b', 'attr' => ['rows' => 16, 'class' => 'form-control code-editor'], 'constraints' => [new NotBlank()]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'editing' => false, 'business_accounts' => []]);
        $resolver->setAllowedTypes('editing', 'bool');
        $resolver->setAllowedTypes('business_accounts', 'array');
    }
}
