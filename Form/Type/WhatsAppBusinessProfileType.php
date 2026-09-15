<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

final class WhatsAppBusinessProfileType extends AbstractType
{
    private const VERTICAL_LABELS = [
        'Não definida' => 'UNDEFINED', 'Outro' => 'OTHER', 'Automotivo' => 'AUTO', 'Beleza' => 'BEAUTY',
        'Vestuário' => 'APPAREL', 'Educação' => 'EDU', 'Entretenimento' => 'ENTERTAIN',
        'Eventos' => 'EVENT_PLAN', 'Finanças' => 'FINANCE', 'Alimentos e mercado' => 'GROCERY',
        'Governo' => 'GOVT', 'Hotelaria' => 'HOTEL', 'Saúde' => 'HEALTH', 'Sem fins lucrativos' => 'NONPROFIT',
        'Serviços profissionais' => 'PROF_SERVICES', 'Varejo' => 'RETAIL', 'Viagens' => 'TRAVEL',
        'Restaurante' => 'RESTAURANT', 'Não é um negócio' => 'NOT_A_BIZ',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('profile_picture', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Nova foto do perfil',
                'help' => 'JPEG ou PNG, até 5 MB. A imagem será enviada diretamente à Meta.',
                'constraints' => [new File(maxSize: '5M', mimeTypes: ['image/jpeg', 'image/png'])],
                'attr' => ['accept' => 'image/jpeg,image/png'],
            ])
            ->add('about', TextareaType::class, [
                'label' => 'Sobre', 'attr' => ['maxlength' => 139, 'rows' => 2],
                'constraints' => [new NotBlank(), new Length(max: 139)],
            ])
            ->add('description', TextareaType::class, [
                'required' => false, 'label' => 'Descrição', 'attr' => ['maxlength' => 256, 'rows' => 3],
                'constraints' => [new Length(max: 256)],
            ])
            ->add('address', TextareaType::class, [
                'required' => false, 'label' => 'Endereço', 'attr' => ['maxlength' => 256, 'rows' => 2],
                'constraints' => [new Length(max: 256)],
            ])
            ->add('email', EmailType::class, [
                'required' => false, 'label' => 'E-mail público', 'attr' => ['maxlength' => 128],
                'constraints' => [new Email(), new Length(max: 128)],
            ])
            ->add('website_1', UrlType::class, [
                'required' => false, 'label' => 'Site 1', 'attr' => ['maxlength' => 256],
                'constraints' => [new Url(protocols: ['http', 'https']), new Length(max: 256)],
            ])
            ->add('website_2', UrlType::class, [
                'required' => false, 'label' => 'Site 2', 'attr' => ['maxlength' => 256],
                'constraints' => [new Url(protocols: ['http', 'https']), new Length(max: 256)],
            ])
            ->add('vertical', ChoiceType::class, [
                'required' => false, 'label' => 'Categoria', 'placeholder' => 'Sem categoria',
                'choices' => self::VERTICAL_LABELS,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'csrf_token_id' => 'whatsapp_business_profile']);
    }

    public function getBlockPrefix(): string
    {
        return 'whatsapp_business_profile';
    }
}
