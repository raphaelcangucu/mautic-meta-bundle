<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Webhook;

final class WhatsAppWebhookParser
{
    /**
     * @return array{messages:list<array>,statuses:list<array>}
     */
    public function parse(array $payload): array
    {
        $messages = [];
        $statuses = [];
        if ('whatsapp_business_account' !== ($payload['object'] ?? null)) {
            return compact('messages', 'statuses');
        }
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                foreach ($value['messages'] ?? [] as $message) {
                    if (is_array($message) && '' !== $phoneNumberId) {
                        $messages[] = ['phoneNumberId' => $phoneNumberId, 'contact' => $value['contacts'][0] ?? null, 'message' => $message];
                    }
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    if (is_array($status) && '' !== $phoneNumberId) {
                        $statuses[] = ['phoneNumberId' => $phoneNumberId, 'status' => $status];
                    }
                }
            }
        }

        return compact('messages', 'statuses');
    }
}
