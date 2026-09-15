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
            'help' => $editing ? 'mautic.meta.ui.keep_encrypted_value' : null,
        ];
        $builder
            ->add('name', TextType::class, $required + ['label' => 'mautic.core.name'])
            ->add('app_id', TextType::class, $required + ['label' => 'mautic.meta.ui.meta_app_id_88fbc7'])
            ->add('app_secret', PasswordType::class, $secret + ['label' => 'mautic.meta.ui.meta_app_secret_528985'])
            ->add('access_token', PasswordType::class, $secret + ['label' => 'mautic.meta.ui.system_user_token_6e708e'])
            ->add('verify_token', PasswordType::class, $secret + ['label' => 'mautic.meta.ui.webhook_verification_token_46a3cc'])
            ->add('consent_source_url', TextType::class, [
                'required' => false,
                'label' => 'mautic.meta.ui.landing_consent_evidence_url_6aa5b1',
                'help' => 'mautic.meta.ui.https_endpoint_that_reads_persisted_landing_submissions_93e230',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('consent_source_secret', PasswordType::class, [
                'required' => false,
                'always_empty' => true,
                'label' => 'mautic.meta.ui.landing_consent_evidence_secret_944ed8',
                'help' => $editing ? 'mautic.meta.ui.keep_encrypted_value' : 'mautic.meta.ui.hmac_secret',
                'attr' => ['class' => 'form-control', 'autocomplete' => 'new-password'],
            ])
            ->add('graph_version', TextType::class, [
                'label' => 'mautic.meta.ui.graph_api_version_adc171', 'data' => $options['data']['graph_version'] ?? 'v26.0',
                'constraints' => [new NotBlank(), new Regex('/^v\d+\.\d+$/')], 'attr' => ['class' => 'form-control'],
            ])
            ->add('webhook_adapters_json', TextareaType::class, [
                'required' => false,
                'label' => 'mautic.meta.ui.omnichannel_webhook_adapters_json_896960',
                'help' => 'mautic.meta.ui.one_or_more_destinations_each_item_accepts_name_url_secret_enable_3376b3',
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
