<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Mcp\Tool;

use MauticPlugin\MauticMetaBundle\Mcp\Application\MetaService;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\AbstractMcpTool;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

#[McpTool(name: 'mautic_read_meta_api', annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
final class ReadMetaApiTool extends AbstractMcpTool
{
    public function __construct(
        private MetaService $service
    ) {}

    /**
     * Read live Facebook Page profile, posts, reels and comments, or Instagram profile, media, comments, insights, conversations, or conversation messages from Meta Graph API.
     *
     * @param list<string> $metrics
     */
    #[McpTool(name: 'mautic_read_meta_api', annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
    public function __invoke(#[Schema(enum: ['facebook_profile','facebook_posts','facebook_reels','facebook_comments','instagram_profile', 'instagram_media', 'instagram_comments', 'instagram_insights', 'instagram_conversations', 'instagram_conversation_messages'])] string $action, int $assetId, ?string $resourceId = null, #[Schema(type: 'array', items: ['type' => 'string'])] array $metrics = [], int $limit = 50, ?string $after = null): array
    {
        $this->bootstrapExecution();

        return $this->service->readApi($action, $assetId, $resourceId, $metrics, $limit, $after);
    }
}
