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
        yield [AssetType::FacebookPage, Channel::Instagram];
    }
}
