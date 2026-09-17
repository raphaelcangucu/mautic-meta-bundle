<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Mcp\Application;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use MauticPlugin\MauticMetaBundle\Mcp\Application\MetaSetupService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MetaSetupServiceTest extends TestCase
{
    public function testTemplatesSectionDocumentsNumberedMenuAndAgentRules(): void
    {
        $guide = $this->service()->guide('templates');

        self::assertSame('0.14.1', $guide['plugin']['version']);
        self::assertContains('templates', $guide['availableSections']);
        self::assertSame('mautic_manage_meta', $guide['guide']['templates']['mcp']['create']['tool']);
        self::assertStringContainsString('QUICK_REPLY', $guide['guide']['templates']['submit']['components']);
        self::assertStringContainsString('João', $guide['guide']['templates']['submit']['components']);
        self::assertSame(3, $guide['guide']['templates']['submit']['limits']['maxQuickReplies']);
        self::assertStringContainsString('PIX', $guide['guide']['templates']['submit']['copy']);
        self::assertStringContainsString('Template Library', $guide['guide']['templates']['submit']['surveys']);
    }

    public function testMcpAndTroubleshootingPointAgentsAtTemplateNormalization(): void
    {
        $guide = $this->service()->guide('all');

        self::assertStringContainsString('section=templates', $guide['guide']['mcp']['templates']);
        self::assertArrayHasKey('whatsapp_template_invalid_format', $guide['guide']['troubleshooting']);
    }

    private function service(): MetaSetupService
    {
        $permissions = $this->createMock(CorePermissions::class);
        $permissions->method('isGranted')->willReturn(true);
        $permissions->method('checkPermissionExists')->willReturn(true);

        $connections = $this->createMock(MetaConnectionRepository::class);
        $connections->method('findBy')->willReturn([]);

        $assets = $this->createMock(MetaAssetRepository::class);
        $assets->method('findBy')->willReturn([]);

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://example.test/mcp');

        return new MetaSetupService($permissions, $connections, $assets, $urls);
    }
}
