<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Automation;

final class ContactTokenResolver
{
    public function resolve(string $content, array $fields): string
    {
        return (string) preg_replace_callback('/\{contactfield=([a-zA-Z0-9_]+)\}/', static fn (array $match): string => (string) ($fields[$match[1]] ?? ''), $content);
    }

    /**
     * @return list<string>
     */
    public function lines(string $content, array $fields): array
    {
        $resolved = $this->resolve($content, $fields);

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $resolved) ?: []), static fn (string $line): bool => '' !== $line));
    }
}
