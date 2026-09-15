<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Form\Type;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

final class WhatsAppCampaignActionType extends AbstractType
{
    public function __construct(
        private MetaAssetRepository $assets
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($this->assets->findEnabledByType(AssetType::WhatsAppPhoneNumber) as $asset) {
            $choices[$asset->getConnection()->getName().' — '.$asset->getName()] = $asset->getId();
        }
        $builder
            ->add('asset_id', ChoiceType::class, ['label' => 'mautic.meta.ui.whatsapp_sender_9f74aa', 'choices' => $choices, 'constraints' => [new NotBlank()]])
            ->add('mode', ChoiceType::class, ['label' => 'mautic.meta.ui.message_type_d35f04', 'choices' => ['mautic.meta.ui.approved_template' => 'template', 'mautic.meta.ui.free_form_text' => 'text']])
            ->add('phone_field', TextType::class, ['label' => 'mautic.meta.ui.contact_phone_field_b135dd', 'data' => $options['data']['phone_field'] ?? 'mobile', 'constraints' => [new NotBlank()]])
            ->add('template_name', TextType::class, ['label' => 'mautic.meta.ui.template_name_077fcb', 'required' => false])
            ->add('language', TextType::class, ['label' => 'mautic.meta.ui.template_language_c2b48e', 'required' => false, 'data' => $options['data']['language'] ?? 'pt_BR'])
            ->add('body_parameters', TextareaType::class, ['label' => 'mautic.meta.ui.template_body_parameters_one_per_line_a3424a', 'required' => false, 'attr' => ['rows' => 5]])
            ->add('message', TextareaType::class, ['label' => 'mautic.meta.ui.text_message_33f6cb', 'required' => false, 'attr' => ['rows' => 8]])
            ->add('queue', CheckboxType::class, ['label' => 'mautic.meta.ui.queue_with_automatic_retries_3cf136', 'required' => false, 'data' => $options['data']['queue'] ?? true])
            ->add('max_attempts', IntegerType::class, ['label' => 'mautic.meta.ui.maximum_attempts_7b6f5a', 'data' => $options['data']['max_attempts'] ?? 5, 'attr' => ['min' => 1, 'max' => 10]]);
    }

    public function getBlockPrefix(): string { return 'meta_whatsapp_campaign_action'; }
}
