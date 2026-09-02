<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Security;

use Mautic\CoreBundle\Helper\EncryptionHelper;

final class CredentialVault
{
    public function __construct(
        private EncryptionHelper $encryption,
    ) {
    }

    public function seal(string $secret): string
    {
        if ('' === trim($secret)) {
            throw new \InvalidArgumentException('A credential cannot be empty.');
        }

        return $this->encryption->encrypt($secret);
    }

    public function open(string $sealed): string
    {
        $value = $this->encryption->decrypt($sealed);
        if (!is_string($value) || '' === $value) {
            throw new \RuntimeException('Unable to decrypt Meta credential.');
        }

        return $value;
    }
}
