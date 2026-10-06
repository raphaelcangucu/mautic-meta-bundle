<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Domain;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Domain\Channel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssetTypeTest extends TestCase
{
    #[DataProvider('types')]
    public function testMapsAssetToChannel(AssetType $type, Channel $channel): void
    {
        self::assertSame($channel, $type->channel());
    }

    public static function types(): iterable
    {
        yield [AssetType::WhatsAppBusinessAccount, Channel::WhatsApp];
        yield [AssetType::WhatsAppPhoneNumber, Channel::WhatsApp];
        yield [AssetType::InstagramAccount, Channel::Instagram];
        yield [AssetType::FacebookPage, Channel::Facebook];
    }

    public function testTheQrSessionIsAWhatsAppChannel(): void
    {
        self::assertSame(Channel::WhatsApp, AssetType::WhatsAppQrSession->channel());
    }

    public function testEveryCaseAnswersItsChannel(): void
    {
        // O match de channel() e exaustivo: um caso sem braco estoura em runtime, e nao
        // na compilacao. Este teste e o que transforma isso em vermelho aqui, agora.
        foreach (AssetType::cases() as $caso) {
            self::assertInstanceOf(Channel::class, $caso->channel());
        }
    }

    #[DataProvider('graphAssets')]
    public function testKnowsWhichTypesTheGraphApiCanAnswerFor(AssetType $type, bool $isGraphAsset): void
    {
        self::assertSame($isGraphAsset, $type->isGraphAsset());
    }

    public static function graphAssets(): iterable
    {
        yield [AssetType::WhatsAppBusinessAccount, true];
        yield [AssetType::WhatsAppPhoneNumber, true];
        yield [AssetType::InstagramAccount, true];
        yield [AssetType::FacebookPage, true];
        yield [AssetType::WhatsAppQrSession, false];
    }

    public function testEveryCaseAnswersWhetherItLivesOnTheGraph(): void
    {
        // Quem consome esta pergunta filtra por ela em telas de producao. Deixar a resposta
        // aqui, exaustiva, obriga quem acrescentar um canal a decidir neste arquivo -- e nao
        // a descobrir em runtime que uma lista de tipos espalhada por ai ficou desatualizada.
        foreach (AssetType::cases() as $caso) {
            self::assertIsBool($caso->isGraphAsset());
        }
    }
}
