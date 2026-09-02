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
