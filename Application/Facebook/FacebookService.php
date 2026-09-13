<?php

declare(strict_types=1);
namespace MauticPlugin\MauticMetaBundle\Application\Facebook;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Application\Safety\OutboundPolicy;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;

final class FacebookService
{
    public function __construct(private MetaGraphClientInterface $graph, private PageConnectionResolver $connections,
        private EntityManagerInterface $entityManager, private IdentityManager $identities,
        private OutboundPolicy $policy, private ConversationManager $conversations, private \MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface $inbox) {}

    public function send(MetaAsset $page, string $recipient, string $text, bool $public, ?Lead $contact = null, bool $human = false): MetaMessage
    {
        if (false === ($page->getSettings()['facebook_reply_enabled'] ?? true)) { throw new \DomainException('Facebook sending requires additional Meta permissions.'); }
        $text = trim($text);
        if ('' === $text || mb_strlen($text) > 2000) { throw new \InvalidArgumentException('Escreva uma resposta de até 2.000 caracteres.'); }
        $connection = $this->connections->resolve($page);
        $type = $public ? 'comment_reply' : 'direct_message';
        $criteria = ['asset' => $page, 'channel' => 'facebook', 'direction' => 'inbound'];
        $criteria += $public ? ['externalId' => $recipient, 'messageType' => 'comment'] : ['recipient' => $recipient, 'messageType' => ['direct_message', 'postback']];
        $inbound = $this->entityManager->getRepository(MetaMessage::class)->findOneBy($criteria, ['dateAdded' => 'DESC']);
        if (!$inbound instanceof MetaMessage || !empty($inbound->getPayload()['removed'])) { throw new \DomainException('A mensagem ou o comentário de origem não está disponível nesta Página.'); }
        if (!$public && $inbound->getDateAdded() < new \DateTimeImmutable('-24 hours')) { throw new \DomainException('Aguarde uma nova mensagem no Messenger para responder.'); }
        $this->identities->assertChannelContactable($contact, 'facebook');
        $this->policy->assertAllowed($page, 'facebook', $recipient, $type);
        $payload = $public ? ['message' => $text] : ['messaging_type' => 'RESPONSE', 'recipient' => ['id' => $recipient], 'message' => ['text' => $text]];
        $path = $public ? $recipient.'/comments' : $page->getExternalId().'/messages';
        $log = (new MetaMessage())->setAsset($page)->setContact($contact)->setChannel('facebook')->setMessageType($type)->setRecipient($recipient)->setPayload($payload);
        $this->entityManager->persist($log); $this->entityManager->flush();
        try {
            $send = fn (): array => $this->graph->post($connection, $path, $payload);
            $response = $human ? $send() : $this->inbox->runAutomationGuarded($page, $recipient, $send);
            $id = (string) ($response['message_id'] ?? $response['id'] ?? '');
            if ('' === $id) { throw new \RuntimeException('Meta did not return a Facebook message ID.'); }
            $log->setExternalId($id)->setResponse($response)->setStatus('accepted');
            $this->entityManager->persist($log); $this->entityManager->flush();
            $this->conversations->record($log);
            return $log;
        } catch (\Throwable $e) {
            $log->setStatus('failed')->setError($e->getMessage());
            $this->entityManager->persist($log); $this->entityManager->flush(); throw $e;
        }
    }
}
