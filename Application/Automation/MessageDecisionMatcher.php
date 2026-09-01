<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Automation;

use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

final class MessageDecisionMatcher
{
    /**
     * @param array<string, mixed> $properties
     */
    public function matches(MetaMessage $message, array $properties): bool
    {
        foreach (['channel' => $message->getChannel(), 'direction' => $message->getDirection(), 'status' => $message->getStatus(), 'message_type' => $message->getMessageType()] as $property => $actual) {
            $expected = trim((string) ($properties[$property] ?? ''));
            if ('' !== $expected && $expected !== $actual) { return false; }
        }
        $pattern = mb_strtolower(trim((string) ($properties['pattern'] ?? '')));
        if ('' === $pattern) { return true; }
        $payload = $message->getPayload();
        $body = mb_strtolower((string) ($payload['message']['text']['body'] ?? $payload['text'] ?? ''));

        return str_contains($body, $pattern);
    }
}
