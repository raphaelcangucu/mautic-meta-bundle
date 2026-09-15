<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\WhatsApp;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class PhoneNormalizer
{
    public function normalize(string $phone, string $defaultRegion): string
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $parsed = $util->parse(trim($phone), strtoupper($defaultRegion));
        } catch (NumberParseException $exception) {
            throw new \InvalidArgumentException('Invalid WhatsApp phone number.', previous: $exception);
        }
        if (!$util->isValidNumber($parsed)) {
            throw new \InvalidArgumentException('Invalid WhatsApp phone number.');
        }

        return ltrim($util->format($parsed, PhoneNumberFormat::E164), '+');
    }

    public function normalizeMetaSender(string $phone, string $defaultRegion): string
    {
        try {
            return $this->normalizeImported($phone, $defaultRegion);
        } catch (\InvalidArgumentException) {
            return trim($phone);
        }
    }

    /**
     * Return the canonical recipient followed by safe historical aliases.
     *
     * Meta may identify the same Brazilian mobile with or without the ninth
     * digit. Both forms must resolve to one conversation, while the message
     * itself keeps the exact recipient used for delivery auditing.
     *
     * @return list<string>
     */
    public function equivalentRecipients(string $phone, string $defaultRegion): array
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        $canonical = $this->normalizeMetaSender($digits ?: $phone, $defaultRegion);
        $recipients = [$canonical];

        if ('BR' === strtoupper($defaultRegion)) {
            if (preg_match('/^55[1-9][1-9]9[6-9][0-9]{7}$/', $canonical)) {
                $recipients[] = substr($canonical, 0, 4).substr($canonical, 5);
            } elseif (preg_match('/^55[1-9][1-9][6-9][0-9]{7}$/', $digits)) {
                $recipients[] = substr($digits, 0, 4).'9'.substr($digits, 4);
                $recipients[] = $digits;
            }
        }

        if ('' !== $digits) {
            $recipients[] = $digits;
        }

        return array_values(array_unique(array_filter($recipients, static fn (string $recipient): bool => '' !== $recipient)));
    }

    public function normalizeImported(string $phone, string $defaultRegion, bool $convertLegacyBrazilianMobile = true): string
    {
        try {
            return $this->normalize($phone, $defaultRegion);
        } catch (\InvalidArgumentException $exception) {
            if (!$convertLegacyBrazilianMobile || 'BR' !== strtoupper($defaultRegion)) {
                throw $exception;
            }

            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            if (str_starts_with($digits, '55') && 12 === strlen($digits)) {
                $digits = substr($digits, 2);
            }
            if (10 !== strlen($digits) || !preg_match('/^[1-9][1-9][6-9][0-9]{7}$/', $digits)) {
                throw $exception;
            }

            return $this->normalize(substr($digits, 0, 2).'9'.substr($digits, 2), 'BR');
        }
    }
}
