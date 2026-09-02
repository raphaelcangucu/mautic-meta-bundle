<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Infrastructure;

use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphApiException;
use PHPUnit\Framework\TestCase;

final class MetaGraphApiExceptionTest extends TestCase
{
    public function testExposesOriginalGraphErrorAndRequestContext(): void
    {
        $exception = new MetaGraphApiException(
            'GET',
            'https://graph.facebook.com/v26.0/profile-id',
            400,
            [
                'message'       => 'Application does not have permission for this action',
                'type'          => 'OAuthException',
                'code'          => 10,
                'error_subcode' => 123,
                'fbtrace_id'    => 'trace-id',
            ],
        );

        self::assertSame([
            'message'       => 'Application does not have permission for this action',
            'type'          => 'OAuthException',
            'code'          => 10,
            'error_subcode' => 123,
            'fbtrace_id'    => 'trace-id',
            'http_status'   => 400,
            'method'        => 'GET',
            'endpoint'      => 'https://graph.facebook.com/v26.0/profile-id',
        ], $exception->details());
        self::assertFalse($exception->isRetryable());
    }
}
