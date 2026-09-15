<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\WhatsApp;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppBusinessProfileManager;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WhatsAppBusinessProfileManagerTest extends TestCase
{
    public function testReadsPublicProfileAndApprovedDisplayName(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::exactly(2))->method('get')->willReturnCallback(
            static fn (MetaConnection $connection, string $path, array $query): array => str_ends_with($path, '/whatsapp_business_profile')
                ? ['data' => [['business_profile' => [
                    'about' => 'Atendimento', 'address' => 'Rua Um', 'description' => 'Descrição',
                    'email' => 'contato@example.com', 'websites' => ['https://example.com'],
                    'vertical' => 'PROF_SERVICES', 'profile_picture_url' => 'https://pps.whatsapp.net/profile.jpg',
                ]]]]
                : ['verified_name' => 'Codificar Sistemas', 'display_phone_number' => '+55 31 7544-1171', 'name_status' => 'APPROVED'],
        );

        $profile = (new WhatsAppBusinessProfileManager($graph, $this->createMock(EntityManagerInterface::class)))->profile($this->phone());

        self::assertSame('Codificar Sistemas', $profile['verified_name']);
        self::assertSame('Atendimento', $profile['about']);
        self::assertSame('https://pps.whatsapp.net/profile.jpg', $profile['profile_picture_url']);
    }

    public function testUpdatesOnlyFieldsSupportedByCloudApiAndStoresSnapshot(): void
    {
        $phone = $this->phone();
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::never())->method('upload');
        $graph->expects(self::once())->method('post')->with(
            $phone->getConnection(),
            '1236834432856918/whatsapp_business_profile',
            self::callback(static function (array $payload): bool {
                self::assertArrayNotHasKey('display_name', $payload);
                self::assertSame('whatsapp', $payload['messaging_product']);
                self::assertSame(['https://example.com', 'https://instagram.com/codificar'], $payload['websites']);

                return 'Codificar soluções' === $payload['about'] && 'PROF_SERVICES' === $payload['vertical'];
            }),
        )->willReturn(['success' => true]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($phone);
        $entityManager->expects(self::once())->method('flush');

        (new WhatsAppBusinessProfileManager($graph, $entityManager))->update($phone, $this->validData());

        self::assertSame('Codificar soluções', $phone->getSettings()['whatsapp_business_profile']['about']);
        self::assertFalse($phone->getSettings()['whatsapp_business_profile']['picture_updated']);
    }

    public function testValidatesAndUploadsRealPngBeforeUpdatingProfile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wa-profile-');
        self::assertIsString($path);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $picture = new UploadedFile($path, 'ignored-name.png', 'image/png', null, true);
        $phone = $this->phone();
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('upload')->with(
            $phone->getConnection(),
            'whatsapp-profile.png',
            self::isType('string'),
            'image/png',
        )->willReturn('profile-picture-handle');
        $graph->expects(self::once())->method('post')->with(
            $phone->getConnection(),
            '1236834432856918/whatsapp_business_profile',
            self::callback(static fn (array $payload): bool => 'profile-picture-handle' === ($payload['profile_picture_handle'] ?? null)),
        )->willReturn(['data' => [['business_profile' => ['about' => 'Codificar soluções']]]]);

        try {
            (new WhatsAppBusinessProfileManager($graph, $this->createMock(EntityManagerInterface::class)))
                ->update($phone, $this->validData(), $picture);
        } finally {
            @unlink($path);
        }

        self::assertTrue($phone->getSettings()['whatsapp_business_profile']['picture_updated']);
    }

    public function testRejectsJavascriptWebsiteBeforeCallingMeta(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::never())->method('post');
        $data = $this->validData();
        $data['website_1'] = 'javascript:alert(1)';

        $this->expectException(\InvalidArgumentException::class);
        (new WhatsAppBusinessProfileManager($graph, $this->createMock(EntityManagerInterface::class)))
            ->update($this->phone(), $data);
    }

    /** @return array<string, string> */
    private function validData(): array
    {
        return [
            'about' => 'Codificar soluções',
            'address' => 'Rua Um, 123',
            'description' => 'Atendimento especializado.',
            'email' => 'contato@example.com',
            'website_1' => 'https://example.com',
            'website_2' => 'https://instagram.com/codificar',
            'vertical' => 'PROF_SERVICES',
        ];
    }

    private function phone(): MetaAsset
    {
        return (new MetaAsset(13))
            ->setConnection((new MetaConnection(5))->setAppId('1437146078305405')->setName('Provider'))
            ->setType(AssetType::WhatsAppPhoneNumber)
            ->setExternalId('1236834432856918')
            ->setName('Codificar interno')
            ->setPhoneNumber('+55 31 7544-1171');
    }
}
