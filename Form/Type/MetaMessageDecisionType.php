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
            ->add('channel', ChoiceType::class, ['required' => false, 'label' => 'Channel', 'placeholder' => 'Any channel', 'choices' => ['WhatsApp' => 'whatsapp', 'Instagram' => 'instagram']])
            ->add('direction', ChoiceType::class, ['required' => false, 'label' => 'Direction', 'placeholder' => 'Any direction', 'choices' => ['Inbound' => 'inbound', 'Outbound' => 'outbound']])
            ->add('status', ChoiceType::class, ['required' => false, 'label' => 'Delivery status', 'placeholder' => 'Any status', 'choices' => ['Received' => 'received', 'Accepted' => 'accepted', 'Sent' => 'sent', 'Delivered' => 'delivered', 'Read' => 'read', 'Failed' => 'failed']])
            ->add('message_type', TextType::class, ['required' => false, 'label' => 'Message type (optional)'])
            ->add('pattern', TextType::class, ['required' => false, 'label' => 'Inbound text contains (optional)']);
    }
}
