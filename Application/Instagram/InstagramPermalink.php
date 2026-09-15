<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Instagram;

final readonly class InstagramPermalink
{
    private function __construct(
        public string $normalized,
        public string $shortcode,
        public string $canonical,
    ) {
    }

    public static function fromString(string $value): self
    {
        $parts = parse_url(trim($value));
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('A valid Instagram post or reel permalink is required.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (
            !in_array($scheme, ['http', 'https'], true)
            || !in_array($host, ['instagram.com', 'www.instagram.com'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            throw new \InvalidArgumentException('A valid Instagram post or reel permalink is required.');
        }

        $path = (string) ($parts['path'] ?? '');
        if (1 !== preg_match('#^/(p|reel)/([A-Za-z0-9_-]{1,64})/?$#', $path, $matches)) {
            throw new \InvalidArgumentException('A valid Instagram post or reel permalink is required.');
        }

        $type = $matches[1];
        $shortcode = $matches[2];

        return new self(
            'instagram.com/'.$type.'/'.$shortcode,
            $shortcode,
            'https://www.instagram.com/'.$type.'/'.$shortcode.'/',
        );
    }
}
