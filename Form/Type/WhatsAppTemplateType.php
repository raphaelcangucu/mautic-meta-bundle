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
            ->add('business_account_id', ChoiceType::class, ['label' => 'WhatsApp Business Account', 'choices' => $options['business_accounts'], 'disabled' => $editing, 'constraints' => [new NotBlank()]])
            ->add('name', TextType::class, ['label' => 'Nome do modelo', 'disabled' => $editing, 'constraints' => [new NotBlank()]])
            ->add('language', TextType::class, ['label' => 'Idioma', 'disabled' => $editing, 'constraints' => [new NotBlank()]])
            ->add('category', ChoiceType::class, ['label' => 'Categoria', 'choices' => ['Marketing' => 'MARKETING', 'Utility' => 'UTILITY', 'Authentication' => 'AUTHENTICATION']])
            ->add('components_json', TextareaType::class, ['label' => 'Componentes (JSON)', 'attr' => ['rows' => 16, 'class' => 'form-control code-editor'], 'constraints' => [new NotBlank()]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'editing' => false, 'business_accounts' => []]);
        $resolver->setAllowedTypes('editing', 'bool');
        $resolver->setAllowedTypes('business_accounts', 'array');
    }
}
