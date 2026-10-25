<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Queue;

use MauticPlugin\MauticMetaBundle\Application\Queue\ChannelDispatcherInterface;
use MauticPlugin\MauticMetaBundle\Application\Queue\ChannelDispatchers;
use MauticPlugin\MauticMetaBundle\Application\Queue\NoopChannelDispatcher;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundOperationExecutor;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramService;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppSender;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use PHPUnit\Framework\TestCase;

final class ChannelDispatchersTest extends TestCase
{
    public function testRoutesTheJobByItsAssetType(): void
    {
        $message = new MetaMessage(71);
        $email = $this->dispatcherReturning($message);
        $job = $this->emailJob();

        self::assertSame($message, (new ChannelDispatchers(['email_account' => $email]))->dispatch($job));
    }

    public function testWithoutADispatcherTheJobFailsForGood(): void
    {
        // DomainException e falha definitiva para a fila; RuntimeException seria "incerto",
        // e um job incerto nao pode ser tentado de novo.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('email_account');

        (new ChannelDispatchers())->dispatch($this->emailJob());
    }

    public function testADispatcherForAnotherTypeIsNeverUsed(): void
    {
        $other = $this->createMock(ChannelDispatcherInterface::class);
        $other->expects(self::never())->method('dispatch');

        $this->expectException(\DomainException::class);

        (new ChannelDispatchers(['whatsapp_qr_session' => $other], new NoopChannelDispatcher()))->dispatch($this->emailJob());
    }

    public function testTheExecutorHandsEmailJobsToTheDispatchers(): void
    {
        $message = new MetaMessage(72);
        // Os dois servicos sao final e nao sao tocados por um job de e-mail: basta existirem.
        $executor = new OutboundOperationExecutor(
            (new \ReflectionClass(WhatsAppSender::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(InstagramService::class))->newInstanceWithoutConstructor(),
            null,
            null,
            new ChannelDispatchers(['email_account' => $this->dispatcherReturning($message)]),
        );

        self::assertSame($message, $executor->execute($this->emailJob()));
    }

    private function emailJob(): MetaOutboundJob
    {
        $asset = (new MetaAsset(9))->setType(AssetType::EmailAccount)->setExternalId(str_repeat('a', 32));

        return (new MetaOutboundJob(30))->setAsset($asset)->setOperation('email_message')->setPayload(['recipient' => 'lead@example.com', 'text' => 'Oi', '_origin' => 'inbox_human']);
    }

    private function dispatcherReturning(MetaMessage $message): ChannelDispatcherInterface
    {
        $dispatcher = $this->createMock(ChannelDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willReturn($message);

        return $dispatcher;
    }
}
