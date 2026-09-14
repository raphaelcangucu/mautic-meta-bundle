<?php

declare(strict_types=1);
namespace MauticPlugin\MauticMetaBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate;

final class MetaUiTest extends MauticMysqlTestCase
{
    public function testMenusReturnNativeAjaxEnvelope(): void
    {
        foreach (['/s/meta','/s/meta/connections','/s/meta/whatsapp/templates','/s/meta/identities','/s/meta/operations?tab=jobs','/s/meta/operations?tab=messages','/s/meta/operations?tab=events','/s/meta/operations?tab=deliveries','/s/meta/operations?tab=jobs&status=&asset=&channel=&operation=facebook_direct_message&from=&to=&limit=25','/s/meta?asset=&period=all'] as $url) {
            $this->client->xmlHttpRequest('GET', $url);
            self::assertResponseIsSuccessful();
            $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('meta', $data['mauticContent']);
            self::assertStringContainsString('meta-ui', $data['newContent']);
        }
    }

    public function testFiltersPaginateMatchingAssetsAndTemplates(): void
    {
        $connection = (new MetaConnection())->setName('UI test');
        $this->em->persist($connection);
        for ($i = 1; $i <= 27; ++$i) {
            $asset = (new MetaAsset())->setConnection($connection)->setName(sprintf('UI account %02d', $i))->setExternalId('ui-'.$i)->setType(AssetType::InstagramAccount);
            $this->em->persist($asset);
        }
        $waba = (new MetaAsset())->setConnection($connection)->setName('WABA ui')->setExternalId('ui-waba')->setType(AssetType::WhatsAppBusinessAccount);
        $this->em->persist($waba);
        $template = (new WhatsAppTemplate())->setBusinessAccount($waba)->setName('ui_template')->setLanguage('pt_BR')->setCategory('UTILITY')->setStatus('APPROVED')->setComponents([['type'=>'BODY','text'=>'Olá']]);
        $this->em->persist($template); $this->em->flush();
        $crawler = $this->client->request('GET','/s/meta/connections?search=UI+account&type=instagram_account&limit=25&page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.meta-row'));
        self::assertStringContainsString('27 resultados', $crawler->text());
        $crawler = $this->client->request('GET','/s/meta/whatsapp/templates?search=ui_template&status=APPROVED');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ui_template', $crawler->text());
        $this->client->request('GET','/s/meta/assets/'.$waba->getId().'/edit'); self::assertResponseIsSuccessful();
        $this->client->request('GET','/s/meta/connections/'.$connection->getId().'/edit'); self::assertResponseIsSuccessful();
        $this->client->request('GET','/s/meta/whatsapp/templates/'.$template->getId().'/edit'); self::assertResponseIsSuccessful();
    }
}
