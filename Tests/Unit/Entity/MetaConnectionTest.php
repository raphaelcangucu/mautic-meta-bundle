<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Entity;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use PHPUnit\Framework\TestCase;

final class MetaConnectionTest extends TestCase
{
    public function testAConnectionMadeOnlyOfQrSessionsStaysOutOfTheMetaListing(): void
    {
        // A linha de uma conexao por QR nasce sem appId, sem segredo e sem token: na tela de
        // Conexoes ela so renderia um botao de testar acesso que nunca teria o que testar.
        self::assertFalse($this->connection(AssetType::WhatsAppQrSession)->isOnGraph());
    }

    public function testConnectionsWithOfficialAssetsStayInTheListing(): void
    {
        self::assertTrue($this->connection(AssetType::FacebookPage)->isOnGraph());
        self::assertTrue($this->connection(AssetType::InstagramAccount, AssetType::WhatsAppPhoneNumber)->isOnGraph());
    }

    public function testAConnectionThatMixesQrWithOfficialAssetsStaysInTheListing(): void
    {
        // Esconder a conexao inteira levaria junto os assets oficiais dela, que continuam
        // dependendo do Graph e precisam do teste de acesso.
        self::assertTrue($this->connection(AssetType::WhatsAppBusinessAccount, AssetType::WhatsAppQrSession)->isOnGraph());
    }

    public function testAFreshConnectionWithoutAssetsStaysInTheListing(): void
    {
        // Conexao recem-criada ainda vai receber os assets pela propria tela: some-la seria
        // deixar o usuario sem caminho de volta.
        self::assertTrue($this->connection()->isOnGraph());
    }

    private function connection(AssetType ...$types): MetaConnection
    {
        $connection = (new MetaConnection(4))->setAppId('app-1');
        $assetId    = 10;
        foreach ($types as $type) {
            $connection->addAsset(
                (new MetaAsset(++$assetId))->setType($type)->setExternalId('asset-'.$type->value)->setName($type->value),
            );
        }

        return $connection;
    }
}
