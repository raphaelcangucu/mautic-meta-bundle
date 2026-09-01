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
}
