<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Symfony\Component\HttpFoundation\Response;

final class InstagramMediaApiAuthenticationTest extends MauticMysqlTestCase
{
    public function testRouteRequiresApiAuthentication(): void
    {
        $this->client->enableReboot();
        $this->clientServer = [];
        $this->setUpSymfony($this->configParams);

        $this->client->request('GET', '/api/meta/instagram/assets/4/media/resolve', [
            'permalink' => 'https://www.instagram.com/p/DdRsy0CgJk7/',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
