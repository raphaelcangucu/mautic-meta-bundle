<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\WhatsApp;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMetaBundle\Application\Adapter\WebhookAdapterDispatcher;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Application\Safety\OutboundPolicy;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplateRepository;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;

final class WhatsAppSender
{
    public function __construct(
        private MetaGraphClientInterface $graph,
        private EntityManagerInterface $entityManager,
        private PhoneNormalizer $phones,
        private IdentityManager $identities,
        private ?OutboundPolicy $outboundPolicy = null,
        private ?WebhookAdapterDispatcher $adapters = null,
        private ?ConversationManager $conversations = null,
        private ?WhatsAppTemplateRepository $templates = null,
    ) {
    }

    public function sendText(MetaAsset $phoneAsset, string $recipient, string $text, bool $previewUrl = false, ?Lead $contact = null): WhatsAppSendResult
    {
        if ('' === trim($text)) {
            throw new \InvalidArgumentException('WhatsApp text cannot be empty.');
        }

        return $this->send($phoneAsset, $recipient, 'text', ['text' => ['body' => $text, 'preview_url' => $previewUrl]], $contact);
    }

    public function sendTemplate(MetaAsset $phoneAsset, string $recipient, string $name, string $language, array $components = [], ?Lead $contact = null): WhatsAppSendResult
    {
        if ('' === trim($name) || '' === trim($language)) {
            throw new \InvalidArgumentException('WhatsApp template name and language are required.');
        }
        if (null !== $this->templates) {
            $template = $this->templates->findOneBy([
                'name'     => $name,
                'language' => $language,
                'status'   => 'APPROVED',
            ]);
            if (!$template instanceof WhatsAppTemplate || $template->getBusinessAccount()->getConnection()->getId() !== $phoneAsset->getConnection()->getId()) {
                throw new \DomainException('An approved WhatsApp template for this Meta connection is required.');
            }
        }
        $template = ['name' => $name, 'language' => ['code' => $language]];
        if ([] !== $components) {
            $template['components'] = $components;
        }

        return $this->send($phoneAsset, $recipient, 'template', ['template' => $template], $contact);
    }

    public function sendMedia(MetaAsset $phoneAsset, string $recipient, string $mediaType, array $media, ?Lead $contact = null): WhatsAppSendResult
    {
        if (!in_array($mediaType, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
            throw new \InvalidArgumentException('Unsupported WhatsApp media type.');
        }
        if (empty($media['id']) && empty($media['link'])) {
            throw new \InvalidArgumentException('WhatsApp media requires an uploaded id or public link.');
        }

        return $this->send($phoneAsset, $recipient, $mediaType, [$mediaType => $media], $contact);
    }

    public function sendInteractive(MetaAsset $phoneAsset, string $recipient, array $interactive, ?Lead $contact = null): WhatsAppSendResult
    {
        if (!in_array($interactive['type'] ?? null, ['button', 'list', 'product', 'product_list', 'flow'], true) || !is_array($interactive['body'] ?? null)) {
            throw new \InvalidArgumentException('Invalid WhatsApp interactive message.');
        }

        return $this->send($phoneAsset, $recipient, 'interactive', ['interactive' => $interactive], $contact);
    }

    private function send(MetaAsset $asset, string $recipient, string $type, array $content, ?Lead $contact): WhatsAppSendResult
    {
        if (AssetType::WhatsAppPhoneNumber !== $asset->getType() || !$asset->isPublished() || 'active' !== $asset->getStatus()) {
            throw new \InvalidArgumentException('A published, active WhatsApp phone-number asset is required.');
        }
        $region = (string) ($asset->getSettings()['default_region'] ?? 'BR');
        $recipient = $this->phones->normalize($recipient, $region);
        $this->identities->assertCanSend($asset, $recipient, $contact);
        $this->outboundPolicy?->assertAllowed($asset, 'whatsapp', $recipient, $type, $contact?->getId());
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $recipient, 'type' => $type] + $content;
        $log = (new MetaMessage())
            ->setAsset($asset)
            ->setContact($contact)
            ->setChannel('whatsapp')
            ->setMessageType($type)
            ->setRecipient($recipient)
            ->setPayload($payload);
        $this->entityManager->persist($log);
        $this->entityManager->flush();
        try {
            $response = $this->graph->post($asset->getConnection(), $asset->getExternalId().'/messages', $payload);
            $messageId = trim((string) ($response['messages'][0]['id'] ?? ''));
            $messageStatus = (string) ($response['messages'][0]['message_status'] ?? 'accepted');
            $sanitizedResponse = ['http_status' => 200, 'message_status' => $messageStatus, 'wamid' => $messageId, 'meta' => $response];
            if ('' === $messageId) {
                $log->setResponse($sanitizedResponse)->setStatus('failed')->setError('Meta response did not contain response.messages[0].id.');
                $this->entityManager->flush();
                throw new \RuntimeException('Meta response did not contain response.messages[0].id.');
            }
            $log->setExternalId($messageId)->setResponse($sanitizedResponse)->setStatus($messageStatus);
            $this->entityManager->flush();
            $this->conversations?->record($log);
            $this->adapters?->dispatch($log, 'message.sent');

            return new WhatsAppSendResult((int) $log->getId(), $messageId, $messageStatus, $recipient, $sanitizedResponse);
        } catch (\Throwable $exception) {
            if ($exception instanceof \MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphApiException) {
                $details = $exception->details();
                $log->setResponse(['http_status' => $details['http_status'], 'error' => [
                    'message' => $details['message'], 'type' => $details['type'], 'code' => $details['code'],
                    'error_subcode' => $details['error_subcode'], 'fbtrace_id' => $details['fbtrace_id'],
                ]]);
            }
            $log->setError($exception->getMessage())->setStatus('failed');
            $this->entityManager->flush();
            throw $exception;
        }
    }
}
