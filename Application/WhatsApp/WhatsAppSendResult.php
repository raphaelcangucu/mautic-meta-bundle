<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\WhatsApp;

final readonly class WhatsAppSendResult
{
    public function __construct(
        public int $logId,
        public string $messageId,
        public string $status,
        public string $recipient,
        public array $response = [],
        public int $httpStatus = 200,
    ) {}
}
