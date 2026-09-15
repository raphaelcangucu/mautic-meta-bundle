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
            ->add('asset_id', ChoiceType::class, ['label' => 'mautic.meta.ui.instagram_account_a38dde', 'choices' => $choices, 'constraints' => [new NotBlank()]])
            ->add('action', ChoiceType::class, ['label' => 'mautic.meta.ui.action_d621dc', 'choices' => ['mautic.meta.ui.private_comment_reply' => 'private_reply', 'mautic.meta.ui.public_comment_reply' => 'public_reply', 'mautic.meta.ui.direct_message' => 'direct_message']])
            ->add('recipient_field', TextType::class, ['label' => 'mautic.meta.ui.contact_field_containing_comment_id_or_instagram_user_id_aa0d9c', 'constraints' => [new NotBlank()]])
            ->add('message', TextareaType::class, ['label' => 'mautic.meta.ui.message_eaffb6', 'constraints' => [new NotBlank()], 'attr' => ['rows' => 8]])
            ->add('queue', CheckboxType::class, ['label' => 'mautic.meta.ui.queue_with_automatic_retries_3cf136', 'required' => false, 'data' => $options['data']['queue'] ?? true])
            ->add('max_attempts', IntegerType::class, ['label' => 'mautic.meta.ui.maximum_attempts_7b6f5a', 'data' => $options['data']['max_attempts'] ?? 5, 'attr' => ['min' => 1, 'max' => 10]]);
    }

    public function getBlockPrefix(): string { return 'meta_instagram_campaign_action'; }
}
