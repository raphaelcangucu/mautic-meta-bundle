<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class MetaMessageDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('channel', ChoiceType::class, ['required' => false, 'label' => 'mautic.meta.ui.channel_61f21e', 'placeholder' => 'mautic.meta.ui.any_channel_a264a2', 'choices' => ['WhatsApp' => 'whatsapp', 'Instagram' => 'instagram']])
            ->add('direction', ChoiceType::class, ['required' => false, 'label' => 'mautic.meta.ui.direction_5f7970', 'placeholder' => 'mautic.meta.ui.any_direction_0870a6', 'choices' => ['mautic.meta.ui.inbound' => 'inbound', 'mautic.meta.ui.outbound' => 'outbound']])
            ->add('status', ChoiceType::class, ['required' => false, 'label' => 'mautic.meta.ui.delivery_status_3847de', 'placeholder' => 'mautic.meta.ui.any_status_aed0b5', 'choices' => ['mautic.meta.ui.received_c1b6fe' => 'received', 'mautic.meta.ui.accepted' => 'accepted', 'mautic.meta.ui.sent_e1be83' => 'sent', 'mautic.meta.ui.delivered_e0ca94' => 'delivered', 'mautic.meta.ui.read_1c79a9' => 'read', 'mautic.meta.ui.failed_a183ed' => 'failed']])
            ->add('message_type', TextType::class, ['required' => false, 'label' => 'mautic.meta.ui.message_type_optional_45c546'])
            ->add('pattern', TextType::class, ['required' => false, 'label' => 'mautic.meta.ui.inbound_text_contains_optional_6c0a6d']);
    }
}
