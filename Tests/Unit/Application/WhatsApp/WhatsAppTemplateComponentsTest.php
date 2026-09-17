<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\WhatsApp;

use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppTemplateComponents;
use PHPUnit\Framework\TestCase;

final class WhatsAppTemplateComponentsTest extends TestCase
{
    public function testConvertsNumberedMenuIntoQuickReplyButtonsAndAddsSamples(): void
    {
        $components = (new WhatsAppTemplateComponents())->normalize([
            [
                'type' => 'BODY',
                'text' => "Olá, {{1}}! Aqui é João Marcelo, da Macro Markets.\n\nEstamos à disposição caso você tenha alguma dúvida.\n\nResponda com um número:\n1. Tenho uma dúvida.\n2. Gostaria de receber atendimento.\n3. Não preciso de ajuda no momento.\n\nUm membro da nossa equipe continuará o atendimento após sua resposta.",
            ],
            [
                'type' => 'FOOTER',
                'text' => 'Responda SAIR para não receber mensagens.',
            ],
        ]);

        $body = $this->component($components, 'BODY');
        self::assertStringContainsString('Olá, {{1}}!', $body['text']);
        self::assertStringNotContainsString('Responda com um número', $body['text']);
        self::assertStringNotContainsString('Tenho uma dúvida', $body['text']);
        self::assertSame([['João']], $body['example']['body_text']);

        $buttons = $this->component($components, 'BUTTONS')['buttons'];
        self::assertSame('QUICK_REPLY', $buttons[0]['type']);
        self::assertSame('Tenho uma dúvida.', $buttons[0]['text']);
        self::assertSame('Gostaria de receber atend', $buttons[1]['text']);
        self::assertLessThanOrEqual(25, mb_strlen($buttons[1]['text']));
        self::assertCount(3, $buttons);
        self::assertSame('Responda SAIR para não receber mensagens.', $this->component($components, 'FOOTER')['text']);
    }

    public function testConvertsEmojiNumberedSurveyAndKeepsProvidedSamples(): void
    {
        $components = (new WhatsAppTemplateComponents())->normalize([
            [
                'type' => 'BODY',
                'text' => "Oi, {{1}}! Aqui é o João Marcelo.\n\nVi que você criou sua conta.\n\n1️⃣ Não entendi bem como funciona.\n2️⃣ Quero conhecer melhor antes de avançar.\n3️⃣ Ainda estou comparando com outras plataformas.\n\nPode responder só com o número.",
                'example' => ['body_text' => [['Pedro']]],
            ],
        ]);

        $body = $this->component($components, 'BODY');
        self::assertSame([['Pedro']], $body['example']['body_text']);
        self::assertStringNotContainsString('Pode responder só com o número', $body['text']);
        self::assertCount(3, $this->component($components, 'BUTTONS')['buttons']);
        self::assertSame('Não entendi bem como func', $this->component($components, 'BUTTONS')['buttons'][0]['text']);
        self::assertLessThanOrEqual(25, mb_strlen($this->component($components, 'BUTTONS')['buttons'][0]['text']));
    }

    public function testRejectsMoreThanThreeNumberedOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WhatsApp allows at most 3 quick-reply buttons.');
        (new WhatsAppTemplateComponents())->normalize([
            [
                'type' => 'BODY',
                'text' => "Olá, {{1}}!\n\n1. Um\n2. Dois\n3. Três\n4. Quatro\n5. Cinco",
            ],
        ]);
    }

    public function testRejectsBodyThatEndsWithAVariable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot start or end with a variable');
        (new WhatsAppTemplateComponents())->normalize([
            ['type' => 'BODY', 'text' => 'Olá {{1}}'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $components
     * @return array<string, mixed>
     */
    private function component(array $components, string $type): array
    {
        foreach ($components as $component) {
            if (($component['type'] ?? '') === $type) {
                return $component;
            }
        }

        self::fail($type.' component was not produced.');
    }
}
