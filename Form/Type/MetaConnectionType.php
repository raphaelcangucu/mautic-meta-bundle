<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class MetaConnectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $editing = (bool) $options['editing'];
        $required = ['constraints' => [new NotBlank()], 'attr' => ['class' => 'form-control']];
        $secret = [
            'required' => !$editing,
            'constraints' => $editing ? [] : [new NotBlank()],
            'always_empty' => true,
            'attr' => ['class' => 'form-control', 'autocomplete' => 'new-password'],
            'help' => $editing ? 'Leave blank to keep the current encrypted value.' : null,
        ];
        $builder
            ->add('name', TextType::class, $required + ['label' => 'mautic.core.name'])
            ->add('app_id', TextType::class, $required + ['label' => 'Meta App ID'])
            ->add('app_secret', PasswordType::class, $secret + ['label' => 'Meta App Secret'])
            ->add('access_token', PasswordType::class, $secret + ['label' => 'System User Access Token'])
            ->add('verify_token', PasswordType::class, $secret + ['label' => 'Webhook Verify Token'])
            ->add('graph_version', TextType::class, [
                'label' => 'Graph API version', 'data' => $options['data']['graph_version'] ?? 'v26.0',
                'constraints' => [new NotBlank(), new Regex('/^v\d+\.\d+$/')], 'attr' => ['class' => 'form-control'],
            ])
            ->add('webhook_adapters_json', TextareaType::class, [
                'required' => false,
                'label'    => 'Omnichannel webhook adapters (JSON)',
                'help'     => 'One or more destinations. Each item accepts name, url, secret, enabled, events, channels, and timeout.',
                'attr'     => [
                    'rows'        => 12,
                    'placeholder' => '[{"name":"Inbox","url":"https://...","secret":"...","enabled":true,"events":["message.received","message.sent","message.delivered","message.read","message.failed"],"channels":["whatsapp","instagram"]}]',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'editing' => false]);
        $resolver->setAllowedTypes('editing', 'bool');
    }
}
