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
use Symfony\Component\Validator\Constraints\Regex;

final class InstagramCommentDecisionType extends AbstractType
{
    public function __construct(private MetaAssetRepository $assets)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($this->assets->findEnabledByType(AssetType::InstagramAccount) as $asset) {
            $choices[$asset->getConnection()->getName().' — '.$asset->getName()] = $asset->getId();
        }

        $builder
            ->add('asset_id', ChoiceType::class, ['label' => 'mautic.meta.ui.instagram_account_a38dde', 'choices' => $choices, 'constraints' => [new NotBlank()]])
            ->add('media_id', TextType::class, ['label' => 'mautic.meta.ui.exact_instagram_media_id_0b156c', 'constraints' => [new NotBlank(), new Regex('/^[0-9]+$/')]])
            ->add('keyword', TextType::class, ['label' => 'mautic.meta.ui.whole_word_accents_and_case_ignored_09af1b', 'data' => $options['data']['keyword'] ?? 'relatorio', 'constraints' => [new NotBlank(), new Regex('/^[\p{L}\p{N}_]+$/u')]]);
    }

    public function getBlockPrefix(): string
    {
        return 'meta_instagram_comment_decision';
    }
}
