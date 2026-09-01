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

final class InstagramCampaignActionType extends AbstractType
{
    public function __construct(
        private MetaAssetRepository $assets
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($this->assets->findEnabledByType(AssetType::InstagramAccount) as $asset) {
            $choices[$asset->getConnection()->getName().' — '.$asset->getName()] = $asset->getId();
        }
        $builder
            ->add('asset_id', ChoiceType::class, ['label' => 'Instagram account', 'choices' => $choices, 'constraints' => [new NotBlank()]])
            ->add('action', ChoiceType::class, ['label' => 'Action', 'choices' => ['Private reply to comment' => 'private_reply', 'Public comment reply' => 'public_reply', 'Direct message' => 'direct_message']])
            ->add('recipient_field', TextType::class, ['label' => 'Contact field containing comment ID or Instagram user ID', 'constraints' => [new NotBlank()]])
            ->add('message', TextareaType::class, ['label' => 'Message', 'constraints' => [new NotBlank()], 'attr' => ['rows' => 8]])
            ->add('queue', CheckboxType::class, ['label' => 'Queue with automatic retries', 'required' => false, 'data' => $options['data']['queue'] ?? true])
            ->add('max_attempts', IntegerType::class, ['label' => 'Maximum attempts', 'data' => $options['data']['max_attempts'] ?? 5, 'attr' => ['min' => 1, 'max' => 10]]);
    }

    public function getBlockPrefix(): string { return 'meta_instagram_campaign_action'; }
}
