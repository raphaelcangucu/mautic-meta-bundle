<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

final class InstagramCommentPrivateReplyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('message', TextareaType::class, [
            'label' => 'mautic.meta.ui.private_report_reply_3556df',
            'constraints' => [new NotBlank()],
            'attr' => ['rows' => 8],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'meta_instagram_comment_private_reply';
    }
}
