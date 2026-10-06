<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Safety;

use Doctrine\DBAL\Connection;
use MauticPlugin\MauticMetaBundle\Application\Safety\OutboundPolicy;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use PHPUnit\Framework\TestCase;

final class OutboundPolicyTest extends TestCase
{
    public function testBlocksAtConservativeWhatsAppDailyLimit(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchOne')->willReturn(250);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('daily limit');
        (new OutboundPolicy($db))->assertAllowed($this->asset(AssetType::WhatsAppPhoneNumber), 'whatsapp', '5511999999999', 'template');
    }

    public function testBlocksWhatsAppFreeFormOutsideCustomerServiceWindow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(5))->method('fetchOne')->willReturnOnConsecutiveCalls(0, 0, 0, 0, 0);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('last 24 hours');
        (new OutboundPolicy($db))->assertAllowed($this->asset(AssetType::WhatsAppPhoneNumber), 'whatsapp', '5511999999999', 'text');
    }

    public function testAllowsApprovedWhatsAppTemplateWithinLocalLimits(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(4))->method('fetchOne')->willReturn(0);

        (new OutboundPolicy($db))->assertAllowed($this->asset(AssetType::WhatsAppPhoneNumber), 'whatsapp', '5511999999999', 'template');
        self::assertTrue(true);
    }

    public function testHumanTextReplyInsideCustomerServiceWindowBypassesOutboundLimits(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchOne');

        (new OutboundPolicy($db))->assertAllowed(
            $this->asset(AssetType::WhatsAppPhoneNumber),
            'whatsapp',
            '5511999999999',
            'text',
            null,
            true,
        );
        self::assertTrue(true);
    }

    public function testCustomerServiceFlagDoesNotBypassTemplateLimits(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchOne')->willReturn(250);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('daily limit');
        (new OutboundPolicy($db))->assertAllowed(
            $this->asset(AssetType::WhatsAppPhoneNumber),
            'whatsapp',
            '5511999999999',
            'template',
            null,
            true,
        );
    }

    public function testInstagramCommentCooldownOnlyCountsTheSameReplyType(): void
    {
        $queries = [];
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(4))->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $params) use (&$queries): int {
                $queries[] = [$sql, $params];

                return 0;
            }
        );

        (new OutboundPolicy($db))->assertAllowed(
            $this->asset(AssetType::InstagramAccount),
            'instagram',
            'comment-123',
            'comment_reply',
        );

        self::assertStringContainsString('message_type = :messageType', $queries[3][0]);
        self::assertSame('comment_reply', $queries[3][1]['messageType']);
    }

    /**
     * A janela de 24h e regra do WABA, e o canal por QR Code nao e WABA.
     *
     * Aplica-la ali fecharia o canal em duas situacoes que sao o uso normal dele: comecar
     * uma conversa, e responder depois de um dia de silencio. Pior, a saida que a mensagem
     * oferece -- "use um template aprovado" -- nao existe neste canal, entao o atendente
     * leria uma instrucao impossivel de seguir.
     *
     * Os limites de anti-spam continuam valendo, e de proposito: um numero nao homologado
     * e justamente onde disparar em massa termina em banimento.
     */
    public function testTheCustomerServiceWindowDoesNotApplyToAQrSession(): void
    {
        $db = $this->createMock(Connection::class);
        // Quatro chamadas sao os limites locais; a quinta seria a janela de 24h.
        $db->expects(self::exactly(4))->method('fetchOne')->willReturn(0);

        (new OutboundPolicy($db))->assertAllowed($this->asset(AssetType::WhatsAppQrSession), 'whatsapp', '5511999999999', 'text');
        self::assertTrue(true);
    }

    /**
     * O guarda do canal homologado: a janela continua valendo onde a Meta a cobra.
     */
    public function testTheCustomerServiceWindowStillAppliesToAnOfficialNumber(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(5))->method('fetchOne')->willReturn(0);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('last 24 hours');
        (new OutboundPolicy($db))->assertAllowed($this->asset(AssetType::WhatsAppPhoneNumber), 'whatsapp', '5511999999999', 'text');
    }

    private function asset(AssetType $type): MetaAsset
    {
        return (new MetaAsset(7))->setType($type)->setSettings([]);
    }
}
