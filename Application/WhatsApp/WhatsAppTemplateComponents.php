<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\WhatsApp;

/**
 * Rewrites WhatsApp template components into the format Meta reviews as valid.
 *
 * Numbered menus in the body become QUICK_REPLY buttons, positional variables
 * get sample values, and dangling/out-of-order placeholders are rejected.
 */
final class WhatsAppTemplateComponents
{
    private const MAX_QUICK_REPLIES = 3;

    /**
     * @param list<array<string, mixed>> $components
     * @return list<array<string, mixed>>
     */
    public function normalize(array $components): array
    {
        if ([] === $components) {
            throw new \InvalidArgumentException('Template language, valid category, and components are required.');
        }

        $normalized = [];
        $bodySeen = false;
        foreach ($components as $component) {
            if (!is_array($component) || !is_string($component['type'] ?? null)) {
                throw new \InvalidArgumentException('Each template component requires a type.');
            }
            $type = strtoupper((string) $component['type']);
            if ('BODY' === $type) {
                if ($bodySeen) {
                    throw new \InvalidArgumentException('A WhatsApp template can have only one body component.');
                }
                $bodySeen = true;
                $normalized[] = $this->normalizeBody($component);
                continue;
            }
            if ('BUTTONS' === $type) {
                continue;
            }
            $component['type'] = $type;
            $normalized[] = $component;
        }

        $buttons = $this->buttons($components, $normalized);
        if ([] !== $buttons) {
            $normalized[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $component
     * @return array<string, mixed>
     */
    private function normalizeBody(array $component): array
    {
        $parsed = $this->extractNumberedOptions((string) ($component['text'] ?? ''));
        $text = $parsed['text'];
        $this->assertPlaceholders($text);
        $examples = $this->examples($text, is_array($component['example'] ?? null) ? $component['example'] : []);
        $body = ['type' => 'BODY', 'text' => $text];
        if ([] !== $examples) {
            $body['example'] = ['body_text' => [$examples]];
        }
        if ([] !== $parsed['options']) {
            $body['_quick_replies'] = $parsed['options'];
        }

        return $body;
    }

    /**
     * @param list<array<string, mixed>> $original
     * @param list<array<string, mixed>> $normalized
     * @return list<array{type: string, text: string}>
     */
    private function buttons(array $original, array &$normalized): array
    {
        $extracted = [];
        foreach ($normalized as $index => $component) {
            if ('BODY' !== ($component['type'] ?? '') || !isset($component['_quick_replies']) || !is_array($component['_quick_replies'])) {
                continue;
            }
            $extracted = $component['_quick_replies'];
            unset($component['_quick_replies']);
            $normalized[$index] = $component;
        }
        $normalized = array_values($normalized);

        $existing = [];
        foreach ($original as $component) {
            if ('BUTTONS' !== strtoupper((string) ($component['type'] ?? '')) || !is_array($component['buttons'] ?? null)) {
                continue;
            }
            foreach ($component['buttons'] as $button) {
                if (!is_array($button)) {
                    continue;
                }
                $existing[] = $button;
            }
        }

        $quickReplies = [];
        foreach ($existing as $button) {
            if ('QUICK_REPLY' === strtoupper((string) ($button['type'] ?? ''))) {
                $quickReplies[] = $this->quickReply((string) ($button['text'] ?? ''));
            }
        }
        if ([] === $quickReplies) {
            foreach ($extracted as $option) {
                $quickReplies[] = $this->quickReply($option);
            }
        }

        if (count($quickReplies) > self::MAX_QUICK_REPLIES) {
            throw new \InvalidArgumentException('WhatsApp allows at most 3 quick-reply buttons.');
        }

        $merged = [];
        foreach ($existing as $button) {
            if ('QUICK_REPLY' === strtoupper((string) ($button['type'] ?? ''))) {
                continue;
            }
            $merged[] = $button;
        }

        return array_merge($merged, $quickReplies);
    }

    /**
     * @return array{text: string, options: list<string>}
     */
    private function extractNumberedOptions(string $text): array
    {
        $options = [];
        $kept = [];
        foreach (preg_split("/\R/u", $text) as $line) {
            if ($this->isReplyPrompt($line)) {
                continue;
            }
            if (preg_match('/^\s*(?:(?:[1-9]|10)[\.\)\:]|[1-9]\s*[—\-]|[0-9]\x{FE0F}?\x{20E3})\s+(.+?)\s*$/u', $line, $match)) {
                $options[] = trim($match[1]);
                continue;
            }
            $kept[] = $line;
        }

        $text = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)) ?? '');
        if ('' === $text) {
            throw new \InvalidArgumentException('Template body text is required.');
        }

        return ['text' => $text, 'options' => $options];
    }

    private function isReplyPrompt(string $line): bool
    {
        return 1 === preg_match('/responda\s+(apenas\s+)?com\s+um\s+número|pode\s+responder\s+só\s+com\s+o\s+número/iu', $line);
    }

    private function assertPlaceholders(string $text): void
    {
        $trimmed = trim($text);
        if (1 === preg_match('/^\{\{\d+\}\}/', $trimmed) || 1 === preg_match('/\{\{\d+\}\}$/', $trimmed)) {
            throw new \InvalidArgumentException('The template body cannot start or end with a variable.');
        }
        preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);
        $indexes = array_map('intval', $matches[1]);
        if ([] === $indexes) {
            return;
        }
        $unique = array_values(array_unique($indexes));
        sort($unique);
        if ($unique !== range(1, count($unique))) {
            throw new \InvalidArgumentException('Template variables must be sequential starting at {{1}}.');
        }
    }

    /**
     * @param array<string, mixed> $example
     * @return list<string>
     */
    private function examples(string $text, array $example): array
    {
        preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);
        $count = count(array_unique(array_map('intval', $matches[1])));
        if (0 === $count) {
            return [];
        }
        $provided = [];
        if (isset($example['body_text'][0]) && is_array($example['body_text'][0])) {
            $provided = array_values($example['body_text'][0]);
        }
        $samples = [];
        for ($index = 0; $index < $count; ++$index) {
            $value = trim((string) ($provided[$index] ?? ''));
            $samples[] = '' !== $value ? $value : (0 === $index ? 'João' : 'exemplo '.($index + 1));
        }

        return $samples;
    }

    /**
     * @return array{type: string, text: string}
     */
    private function quickReply(string $text): array
    {
        $label = trim($text);
        if ('' === $label) {
            throw new \InvalidArgumentException('Quick-reply buttons require text.');
        }
        if (mb_strlen($label) > 25) {
            $label = rtrim(mb_substr($label, 0, 25));
        }

        return ['type' => 'QUICK_REPLY', 'text' => $label];
    }
}
