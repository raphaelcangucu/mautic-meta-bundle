<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Mcp\Tool;

use MauticPlugin\MauticMetaBundle\Mcp\Tool\ManageMetaTool;
use Mcp\Capability\Attribute\Schema;
use PHPUnit\Framework\TestCase;

final class ManageMetaToolSchemaTest extends TestCase
{
    public function testComponentsSchemaAcceptsNumberedBodyWithoutExamples(): void
    {
        if (!class_exists(Schema::class)) {
            self::markTestSkipped('MCP Schema attribute is not installed.');
        }

        $parameter = (new \ReflectionMethod(ManageMetaTool::class, '__invoke'))->getParameters()[2];
        $attributes = $parameter->getAttributes(Schema::class);
        self::assertNotEmpty($attributes);
        $arguments = $attributes[0]->getArguments();
        $components = $arguments['properties']['components'] ?? null;

        self::assertFalse($arguments['additionalProperties'] ?? true);
        self::assertIsArray($components);
        self::assertStringContainsString('QUICK_REPLY', (string) ($components['description'] ?? ''));
        self::assertTrue($components['items']['additionalProperties'] ?? false);
        self::assertArrayHasKey('text', $components['items']['properties'] ?? []);
        self::assertArrayHasKey('example', $components['items']['properties'] ?? []);
        self::assertSame(['MARKETING', 'UTILITY', 'AUTHENTICATION'], $arguments['properties']['category']['enum'] ?? null);
    }
}
