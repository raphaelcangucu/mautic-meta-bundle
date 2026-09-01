<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Contact;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticMetaBundle\Application\Contact\ContactMatcher;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use PHPUnit\Framework\TestCase;

final class ContactMatcherTest extends TestCase
{
    public function testMatchesUniqueWhatsAppNumberUsingNormalizedPhoneSuffix(): void
    {
        $contact = new Lead();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchFirstColumn')
            ->with(self::stringContains('REGEXP_REPLACE'), ['length' => 11, 'suffix' => '11999999999'])
            ->willReturn(['42']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->expects(self::once())->method('find')->with(Lead::class, 42)->willReturn($contact);

        $matcher = new ContactMatcher($this->createMock(LeadModel::class), $entityManager);

        self::assertSame($contact, $matcher->match($this->asset(AssetType::WhatsAppPhoneNumber), '+55 (11) 99999-9999'));
    }

    public function testRefusesAmbiguousWhatsAppMatch(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn(['42', '43']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->expects(self::never())->method('find');

        $matcher = new ContactMatcher($this->createMock(LeadModel::class), $entityManager);

        self::assertNull($matcher->match($this->asset(AssetType::WhatsAppPhoneNumber), '5511999999999'));
    }

    public function testDoesNotGuessInstagramContactWithoutConfiguredField(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getConnection');
        $matcher = new ContactMatcher($this->createMock(LeadModel::class), $entityManager);

        self::assertNull($matcher->match($this->asset(AssetType::InstagramAccount), '17841400000000000'));
    }

    private function asset(AssetType $type): MetaAsset
    {
        return (new MetaAsset())->setType($type)->setSettings([]);
    }
}
