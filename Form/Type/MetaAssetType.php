<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Form\Type;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

final class MetaAssetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'mautic.core.name', 'constraints' => [new NotBlank()]])
            ->add('type', ChoiceType::class, ['label' => 'Tipo de conta', 'choices' => [
                'WhatsApp Business Account' => AssetType::WhatsAppBusinessAccount->value,
                'WhatsApp phone number' => AssetType::WhatsAppPhoneNumber->value,
                'Instagram professional account' => AssetType::InstagramAccount->value,
                'Facebook Page' => AssetType::FacebookPage->value,
            ]])
            ->add('external_id', TextType::class, ['label' => 'ID da conta na Meta', 'constraints' => [new NotBlank()]])
            ->add('username', TextType::class, ['required' => false, 'label' => 'Usuário do Instagram'])
            ->add('phone_number', TextType::class, ['required' => false, 'label' => 'Telefone de exibição'])
            ->add('default_region', TextType::class, ['required' => false, 'label' => 'País padrão do telefone'])
            ->add('trusted_import_default_region', TextType::class, [
                'required' => false,
                'label' => 'País dos telefones importados',
                'help' => 'País usado para números nacionais importados pela API confiável. Ex.: BR.',
            ])
            ->add('trusted_import_convert_legacy_br_mobile', CheckboxType::class, [
                'required' => false,
                'label' => 'Adicionar o nono dígito aos celulares brasileiros antigos',
            ])
            ->add('contact_match_field', TextType::class, ['required' => false, 'label' => 'Campo do contato para vínculo exato', 'help' => 'Alias do campo com número WhatsApp ou ID Instagram. No WhatsApp, também é considerada a correspondência única de telefone/celular.'])
            ->add('require_opt_in', CheckboxType::class, ['required' => false, 'label' => 'Exigir consentimento explícito para WhatsApp'])
            ->add('daily_send_limit', IntegerType::class, ['required' => false, 'label' => 'Máximo de mensagens por dia', 'help' => 'Pode ser reduzido. Teto: WhatsApp 250; Instagram/Facebook 50.', 'constraints' => [new Positive()]])
            ->add('hourly_send_limit', IntegerType::class, ['required' => false, 'label' => 'Máximo de mensagens por hora', 'help' => 'Teto: WhatsApp 50; Instagram/Facebook 20.', 'constraints' => [new Positive()]])
            ->add('recipient_daily_limit', IntegerType::class, ['required' => false, 'label' => 'Máximo por destinatário por dia', 'help' => 'Entre 1 e 3, para limitar contatos repetidos por campanhas.', 'constraints' => [new Positive()]])
            ->add('recipient_cooldown_seconds', IntegerType::class, ['required' => false, 'label' => 'Intervalo por destinatário (segundos)', 'help' => 'Mínimo: WhatsApp 60 segundos; Instagram/Facebook 300 segundos.', 'constraints' => [new Positive()]])
            ->add('is_default', CheckboxType::class, ['required' => false, 'label' => 'Conta padrão deste canal']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
