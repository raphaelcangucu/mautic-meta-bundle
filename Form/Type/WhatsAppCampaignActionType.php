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
            ->add('asset_id', ChoiceType::class, ['label' => 'WhatsApp sender', 'choices' => $choices, 'constraints' => [new NotBlank()]])
            ->add('mode', ChoiceType::class, ['label' => 'Message type', 'choices' => ['Approved template' => 'template', 'Free-form text (service window)' => 'text']])
            ->add('phone_field', TextType::class, ['label' => 'Contact phone field', 'data' => $options['data']['phone_field'] ?? 'mobile', 'constraints' => [new NotBlank()]])
            ->add('template_name', TextType::class, ['label' => 'Template name', 'required' => false])
            ->add('language', TextType::class, ['label' => 'Template language', 'required' => false, 'data' => $options['data']['language'] ?? 'pt_BR'])
            ->add('body_parameters', TextareaType::class, ['label' => 'Template body parameters, one per line', 'required' => false, 'attr' => ['rows' => 5]])
            ->add('message', TextareaType::class, ['label' => 'Text message', 'required' => false, 'attr' => ['rows' => 8]])
            ->add('queue', CheckboxType::class, ['label' => 'Queue with automatic retries', 'required' => false, 'data' => $options['data']['queue'] ?? true])
            ->add('max_attempts', IntegerType::class, ['label' => 'Maximum attempts', 'data' => $options['data']['max_attempts'] ?? 5, 'attr' => ['min' => 1, 'max' => 10]]);
    }

    public function getBlockPrefix(): string { return 'meta_whatsapp_campaign_action'; }
}
