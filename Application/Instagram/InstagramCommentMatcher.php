<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Instagram;

use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

final class InstagramCommentMatcher
{
    public static function isOwnComment(string $commenterId, string $entryAccountId, string $assetExternalId, string $canonicalId): bool
    {
        return '' !== $commenterId && ($commenterId === $entryAccountId || $commenterId === $assetExternalId || $commenterId === $canonicalId);
    }

    /** @param array<string, mixed> $properties */
    public function matches(MetaMessage $message, array $properties): bool
    {
        if ('instagram' !== $message->getChannel() || 'inbound' !== $message->getDirection() || 'comment' !== $message->getMessageType()) {
            return false;
        }

        $assetId = (int) ($properties['asset_id'] ?? 0);
        $mediaId = trim((string) ($properties['media_id'] ?? ''));
        $keyword = $this->fold(trim((string) ($properties['keyword'] ?? '')));
        if ($assetId <= 0 || $assetId !== $message->getAsset()->getId() || '' === $mediaId || '' === $keyword || 1 !== preg_match('/^[\p{L}\p{N}_]+$/u', $keyword)) {
            return false;
        }

        $payload = $message->getPayload();
        $actualMediaId = (string) ($payload['mediaId'] ?? '');
        $originalMediaId = (string) ($payload['originalMediaId'] ?? '');
        if ($mediaId !== $actualMediaId && $mediaId !== $originalMediaId) {
            return false;
        }

        $text = $this->fold((string) ($payload['text'] ?? ''));

        return 1 === preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($keyword, '/').'(?![\p{L}\p{N}_])/u', $text);
    }

    private function fold(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;

        return strtr($value, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
    }
}
