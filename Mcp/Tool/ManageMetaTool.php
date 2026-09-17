<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Mcp\Tool;

use MauticPlugin\MauticMcpBundle\Application\Management\MutationExecutor;
use MauticPlugin\MauticMetaBundle\Mcp\Application\MetaService;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\AbstractMcpTool;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

#[McpTool(name: 'mautic_manage_meta', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
final class ManageMetaTool extends AbstractMcpTool
{
    public function __construct(
        private MetaService $service,
        private MutationExecutor $mutations
    ) {}

    /**
     * Manage Meta resources. link_identity requires id=an existing Meta Identity ID; upsert_identity creates or updates by contactId + assetId + channel.
     *
     * create_template / update_template: send Graph-style components. A BODY with numbered options (1. / 1) / 1️⃣) or “responda com um número” is accepted; MCP converts those lines to at most 3 QUICK_REPLY buttons and injects missing {{n}} samples (first sample João) before Graph POST. Prefer real BUTTONS over asking the contact to reply with a number. Category: MARKETING (promo/re-engagement), UTILITY (requested account/order updates), AUTHENTICATION (OTP). Max 3 quick replies; extra numbered options are rejected. Do not put gambling, PIX payment asks, or betting palpites in copy. For surveys, start from Meta Template Library instead of a custom numbered quiz. Call mautic_meta_setup section=templates for the submit checklist.
     */
    #[McpTool(name: 'mautic_manage_meta', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
    public function __invoke(#[Schema(enum: ['create_connection', 'update_connection', 'delete_connection', 'create_asset', 'update_asset', 'delete_asset', 'create_template', 'update_template', 'delete_template', 'sync_templates', 'set_consent', 'link_identity', 'upsert_identity', 'test_connection'])] string $action, ?int $id = null, #[Schema(type: 'object', additionalProperties: false, properties: [
        'status' => ['type' => 'string', 'enum' => ['unknown', 'opted_in', 'opted_out']],
        'contactId' => ['type' => ['integer', 'null']],
        'assetId' => ['type' => 'integer'], 'channel' => ['type' => 'string', 'enum' => ['whatsapp', 'instagram']],
        'externalId' => ['type' => 'string', 'pattern' => '^[0-9]{8,32}$', 'description' => 'Opaque Meta ID. Asset IDs may exceed the E.164 15-digit limit; identity upserts still validate WhatsApp numbers separately.'], 'phoneNumber' => ['type' => 'string', 'pattern' => '^\\+[1-9][0-9]{7,14}$'],
        'consentStatus' => ['type' => 'string', 'enum' => ['unknown', 'opted_in', 'opted_out']],
        'consentSource' => ['type' => 'string'], 'consentedAt' => ['type' => 'string', 'format' => 'date-time'],
        'name' => ['type' => 'string'], 'app_id' => ['type' => 'string'], 'app_secret' => ['type' => 'string'],
        'access_token' => ['type' => 'string'], 'verify_token' => ['type' => 'string'], 'graph_version' => ['type' => 'string'],
        'webhook_adapters_json' => ['type' => 'string'],
        'external_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._:-]{1,191}$', 'description' => 'Meta asset ID as returned by Graph API; not an E.164 phone number.'], 'type' => ['type' => 'string', 'enum' => ['whatsapp_business_account', 'whatsapp_phone_number', 'instagram_account', 'facebook_page']],
        'username' => ['type' => ['string', 'null']], 'phone_number' => ['type' => ['string', 'null']], 'is_default' => ['type' => 'boolean'],
        'businessAccountId' => ['type' => 'integer'], 'language' => ['type' => 'string'],
        'category' => ['type' => 'string', 'enum' => ['MARKETING', 'UTILITY', 'AUTHENTICATION'], 'description' => 'create_template/update_template: MARKETING = promo/re-engagement; UTILITY = requested account/order updates; AUTHENTICATION = OTP.'],
        'components' => [
            'type' => 'array',
            'description' => 'create_template/update_template Graph components. Numbered BODY menus (1. / 1) / 1️⃣ / “responda com um número”) are converted to max 3 QUICK_REPLY buttons. example.body_text is optional; missing {{n}} samples are injected (first João). Prefer BUTTONS over reply-with-a-number. Max 3 quick replies. No gambling, PIX payment asks, or betting palpites. Surveys: use Meta Template Library. Component objects may include Graph fields (type, text, example, buttons, format); extra secret keys are not accepted on data.',
            'items' => [
                'type' => 'object',
                'additionalProperties' => true,
                'properties' => [
                    'type' => ['type' => 'string', 'description' => 'BODY, HEADER, FOOTER, or BUTTONS.'],
                    'text' => ['type' => 'string', 'description' => 'Component text. Numbered option lines in BODY are removed and turned into QUICK_REPLY buttons.'],
                    'format' => ['type' => 'string'],
                    'example' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'description' => 'Optional. Omit to let MCP fill body_text samples for {{n}}.',
                        'properties' => [
                            'body_text' => ['type' => 'array', 'description' => 'Sample rows, e.g. [["João"]].'],
                            'header_text' => ['type' => 'array'],
                            'header_handle' => ['type' => 'array'],
                        ],
                    ],
                    'buttons' => [
                        'type' => 'array',
                        'description' => 'Optional. If omitted, numbered BODY options become up to 3 QUICK_REPLY buttons.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                            'properties' => [
                                'type' => ['type' => 'string'],
                                'text' => ['type' => 'string'],
                                'url' => ['type' => 'string'],
                                'phone_number' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'settings' => ['type' => 'object', 'additionalProperties' => true],
    ])] array $data = [], bool $confirm = false, bool $dryRun = false, ?string $idempotencyKey = null): array
    {
        $this->bootstrapExecution();
        $payload = compact('action', 'id', 'data', 'confirm');
        if ($dryRun) { return $this->mutations->dryRun('meta', $action, $this->redactCredentials($payload)); }

        return $this->mutations->execute('meta', $idempotencyKey, $payload, function () use ($action, $id, $data, $confirm, $idempotencyKey): array {
            try {
                return $this->service->manage($action, $id, $data, $confirm, $idempotencyKey);
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $exception) {
                return ['status' => 'rejected', 'error' => ['field' => 'id', 'type' => 'not_found', 'message' => $exception->getMessage()]];
            } catch (\InvalidArgumentException|\DomainException|\Symfony\Component\HttpKernel\Exception\BadRequestHttpException $exception) {
                $field = 'link_identity' === $action && !array_key_exists('contactId', $data) ? 'data.contactId' : 'data';

                return ['status' => 'rejected', 'error' => ['field' => $field, 'type' => 'validation', 'message' => $exception->getMessage()]];
            }
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function redactCredentials(array $payload): array
    {
        if (!is_array($payload['data'] ?? null)) {
            return $payload;
        }
        foreach (['app_secret', 'access_token', 'verify_token'] as $field) {
            if (array_key_exists($field, $payload['data'])) {
                $payload['data'][$field] = '[REDACTED]';
            }
        }

        return $payload;
    }
}
