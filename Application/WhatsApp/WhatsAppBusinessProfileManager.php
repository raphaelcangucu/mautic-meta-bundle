<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\WhatsApp;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WhatsAppBusinessProfileManager
{
    public const VERTICALS = [
        'UNDEFINED', 'OTHER', 'AUTO', 'BEAUTY', 'APPAREL', 'EDU', 'ENTERTAIN', 'EVENT_PLAN',
        'FINANCE', 'GROCERY', 'GOVT', 'HOTEL', 'HEALTH', 'NONPROFIT', 'PROF_SERVICES',
        'RETAIL', 'TRAVEL', 'RESTAURANT', 'NOT_A_BIZ',
    ];

    private const PROFILE_FIELDS = 'about,address,description,email,profile_picture_url,websites,vertical';
    private const MAX_IMAGE_BYTES = 5_000_000;

    public function __construct(
        private MetaGraphClientInterface $graph,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(MetaAsset $phone): array
    {
        $this->assertPhone($phone);
        $response = $this->graph->get($phone->getConnection(), $phone->getExternalId().'/whatsapp_business_profile', [
            'fields' => self::PROFILE_FIELDS,
        ]);
        $row = is_array($response['data'][0] ?? null) ? $response['data'][0] : [];
        if (is_array($row['business_profile'] ?? null)) {
            $row = $row['business_profile'];
        }
        if ([] === $row) {
            throw new \RuntimeException('A Meta não retornou o perfil público deste número do WhatsApp.');
        }

        try {
            $identity = $this->graph->get($phone->getConnection(), $phone->getExternalId(), [
                'fields' => 'verified_name,display_phone_number',
            ]);
        } catch (\Throwable) {
            // The editable public profile is still useful when this optional identity lookup is unavailable.
            $identity = [];
        }

        return $this->normalizeProfile($row) + [
            'verified_name'       => $this->nullableString($identity['verified_name'] ?? null),
            'display_phone_number' => $this->nullableString($identity['display_phone_number'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(MetaAsset $phone, array $data, ?UploadedFile $picture = null): array
    {
        $this->assertPhone($phone);
        $profile = $this->validate($data);
        $payload = ['messaging_product' => 'whatsapp'] + $profile;

        if ($picture instanceof UploadedFile) {
            [$contents, $mimeType, $fileName] = $this->picture($picture);
            $payload['profile_picture_handle'] = $this->graph->upload($phone->getConnection(), $fileName, $contents, $mimeType);
        }

        $response = $this->graph->post($phone->getConnection(), $phone->getExternalId().'/whatsapp_business_profile', $payload);
        if (true !== ($response['success'] ?? false) && !is_array($response['data'][0] ?? null)) {
            throw new \RuntimeException('A Meta não confirmou a atualização do perfil do WhatsApp.');
        }

        $returned = is_array($response['data'][0] ?? null) ? $response['data'][0] : [];
        if (is_array($returned['business_profile'] ?? null)) {
            $returned = $returned['business_profile'];
        }
        $returnedProfile = array_intersect_key($this->normalizeProfile($returned), $returned);
        $settings = $phone->getSettings();
        $current = is_array($settings['whatsapp_business_profile'] ?? null) ? $settings['whatsapp_business_profile'] : [];
        $snapshot = array_replace($current, $profile, $returnedProfile, [
            'synced_at'       => gmdate(DATE_ATOM),
            'picture_updated' => $picture instanceof UploadedFile,
        ]);
        $settings['whatsapp_business_profile'] = $snapshot;
        $phone->setSettings($settings);
        $this->entityManager->persist($phone);
        $this->entityManager->flush();

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{about: string, address: string, description: string, email: string, websites: list<string>, vertical: string}
     */
    private function validate(array $data): array
    {
        $about = trim((string) ($data['about'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $vertical = trim((string) ($data['vertical'] ?? ''));

        if ('' === $about || mb_strlen($about) > 139) {
            throw new \InvalidArgumentException('O campo Sobre deve ter entre 1 e 139 caracteres.');
        }
        foreach (['Endereço' => $address, 'Descrição' => $description] as $label => $value) {
            if (mb_strlen($value) > 256) {
                throw new \InvalidArgumentException($label.' deve ter no máximo 256 caracteres.');
            }
        }
        if (mb_strlen($email) > 128 || ('' !== $email && false === filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new \InvalidArgumentException('Informe um e-mail válido com no máximo 128 caracteres.');
        }
        if ('' !== $vertical && !in_array($vertical, self::VERTICALS, true)) {
            throw new \InvalidArgumentException('Selecione uma categoria de negócio aceita pela Meta.');
        }

        $websites = [];
        foreach (['website_1', 'website_2'] as $key) {
            $url = trim((string) ($data[$key] ?? ''));
            if ('' === $url) {
                continue;
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (mb_strlen($url) > 256 || !in_array($scheme, ['http', 'https'], true) || false === filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('Os sites devem usar uma URL http:// ou https:// válida, com no máximo 256 caracteres.');
            }
            $websites[] = $url;
        }

        return compact('about', 'address', 'description', 'email', 'websites', 'vertical');
    }

    /**
     * @return array{string, string, string}
     */
    private function picture(UploadedFile $picture): array
    {
        if (!$picture->isValid() || !is_file($picture->getPathname())) {
            throw new \InvalidArgumentException('Não foi possível ler a imagem enviada.');
        }
        $size = $picture->getSize();
        if (!is_int($size) || $size < 1 || $size > self::MAX_IMAGE_BYTES) {
            throw new \InvalidArgumentException('A foto deve ter no máximo 5 MB.');
        }
        $image = @getimagesize($picture->getPathname());
        $mimeType = is_array($image) ? (string) ($image['mime'] ?? '') : '';
        if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            throw new \InvalidArgumentException('Envie uma imagem JPEG ou PNG válida.');
        }
        $contents = file_get_contents($picture->getPathname());
        if (!is_string($contents) || '' === $contents) {
            throw new \InvalidArgumentException('Não foi possível ler a imagem enviada.');
        }

        return [$contents, $mimeType, 'whatsapp-profile.'.('image/png' === $mimeType ? 'png' : 'jpg')];
    }

    private function assertPhone(MetaAsset $phone): void
    {
        if (AssetType::WhatsAppPhoneNumber !== $phone->getType() || '' === trim($phone->getExternalId())) {
            throw new \InvalidArgumentException('É necessário um número do WhatsApp com ID Meta válido.');
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalizeProfile(array $row): array
    {
        $websites = array_values(array_filter(array_map(
            static fn (mixed $url): string => trim((string) $url),
            is_array($row['websites'] ?? null) ? $row['websites'] : [],
        ), static fn (string $url): bool => str_starts_with($url, 'http://') || str_starts_with($url, 'https://')));
        $pictureUrl = trim((string) ($row['profile_picture_url'] ?? ''));
        if (!str_starts_with($pictureUrl, 'https://')) {
            $pictureUrl = '';
        }

        return [
            'about'               => trim((string) ($row['about'] ?? '')),
            'address'             => trim((string) ($row['address'] ?? '')),
            'description'         => trim((string) ($row['description'] ?? '')),
            'email'               => trim((string) ($row['email'] ?? '')),
            'websites'            => array_slice($websites, 0, 2),
            'vertical'            => trim((string) ($row['vertical'] ?? '')),
            'profile_picture_url' => $pictureUrl,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
