<?php

declare(strict_types=1);

use MauticPlugin\MauticMetaBundle\Controller\AdapterReplyController;
use MauticPlugin\MauticMetaBundle\Controller\ConnectionController;
use MauticPlugin\MauticMetaBundle\Controller\ConversationController;
use MauticPlugin\MauticMetaBundle\Controller\DashboardController;
use MauticPlugin\MauticMetaBundle\Controller\IdentityController;
use MauticPlugin\MauticMetaBundle\Controller\LandingConsentController;
use MauticPlugin\MauticMetaBundle\Controller\OperationsController;
use MauticPlugin\MauticMetaBundle\Controller\TemplateController;
use MauticPlugin\MauticMetaBundle\Controller\WebhookController;

return [
    'name'        => 'Mautic Meta Integration',
    'description' => 'Multi-account WhatsApp and Instagram integration using the official Meta Graph API.',
    'version'     => '0.10.0',
    'author'      => 'Raphael Cangucu',
    'routes'      => [
        'main' => [
            'mautic_meta_dashboard' => ['path' => '/meta', 'controller' => DashboardController::class.'::index'],
            'mautic_meta_connections' => ['path' => '/meta/connections', 'controller' => ConnectionController::class.'::index'],
            'mautic_meta_connection_new' => ['path' => '/meta/connections/new', 'controller' => ConnectionController::class.'::new', 'method' => ['GET', 'POST']],
            'mautic_meta_connection_edit' => ['path' => '/meta/connections/{connectionId}/edit', 'controller' => ConnectionController::class.'::edit', 'method' => ['GET', 'POST']],
            'mautic_meta_connection_delete' => ['path' => '/meta/connections/{connectionId}/delete', 'controller' => ConnectionController::class.'::delete', 'method' => 'POST'],
            'mautic_meta_asset_new' => ['path' => '/meta/connections/{connectionId}/assets/new', 'controller' => ConnectionController::class.'::newAsset', 'method' => ['GET', 'POST']],
            'mautic_meta_asset_edit' => ['path' => '/meta/assets/{assetId}/edit', 'controller' => ConnectionController::class.'::editAsset', 'method' => ['GET', 'POST']],
            'mautic_meta_asset_delete' => ['path' => '/meta/assets/{assetId}/delete', 'controller' => ConnectionController::class.'::deleteAsset', 'method' => 'POST'],
            'mautic_meta_connection_test' => ['path' => '/meta/connections/{connectionId}/test', 'controller' => ConnectionController::class.'::test', 'method' => 'POST'],
            'mautic_meta_templates' => ['path' => '/meta/whatsapp/templates', 'controller' => TemplateController::class.'::index'],
            'mautic_meta_templates_sync' => ['path' => '/meta/whatsapp/templates/{assetId}/sync', 'controller' => TemplateController::class.'::synchronize', 'method' => 'POST'],
            'mautic_meta_template_new' => ['path' => '/meta/whatsapp/templates/new', 'controller' => TemplateController::class.'::new', 'method' => ['GET', 'POST']],
            'mautic_meta_template_edit' => ['path' => '/meta/whatsapp/templates/{templateId}/edit', 'controller' => TemplateController::class.'::edit', 'method' => ['GET', 'POST']],
            'mautic_meta_template_delete' => ['path' => '/meta/whatsapp/templates/{templateId}/delete', 'controller' => TemplateController::class.'::delete', 'method' => 'POST'],
            'mautic_meta_identities' => ['path' => '/meta/identities/{page}', 'controller' => IdentityController::class.'::index', 'defaults' => ['page' => 1], 'requirements' => ['page' => '\\d+']],
            'mautic_meta_identity_update' => ['path' => '/meta/identities/{identityId}', 'controller' => IdentityController::class.'::update', 'method' => 'POST'],
            'mautic_meta_identity_remove' => ['path' => '/meta/identities/{identityId}/remove', 'controller' => IdentityController::class.'::remove', 'method' => 'POST'],
            'mautic_meta_identity_remove_batch' => ['path' => '/meta/identities/remove-batch', 'controller' => IdentityController::class.'::removeBatch', 'method' => 'POST'],
            'mautic_meta_consent_sync_preview' => ['path' => '/meta/identities/consent-sync/preview', 'controller' => IdentityController::class.'::previewSync', 'method' => 'POST'],
            'mautic_meta_consent_sync_start' => ['path' => '/meta/identities/consent-sync/start', 'controller' => IdentityController::class.'::startSync', 'method' => 'POST'],
            'mautic_meta_consent_sync_cancel' => ['path' => '/meta/identities/consent-sync/{runId}/cancel', 'controller' => IdentityController::class.'::cancelSync', 'method' => 'POST'],
            'mautic_meta_consent_sync_rejections' => ['path' => '/meta/identities/consent-sync/{runId}/rejections', 'controller' => IdentityController::class.'::rejections', 'method' => 'GET'],
            'mautic_meta_operations' => ['path' => '/meta/operations', 'controller' => OperationsController::class.'::index'],
            'mautic_meta_conversations' => ['path' => '/meta/inbox', 'controller' => ConversationController::class.'::index', 'defaults' => ['conversationId' => null]],
            'mautic_meta_conversation_view' => ['path' => '/meta/inbox/{conversationId}', 'controller' => ConversationController::class.'::index', 'method' => 'GET'],
            'mautic_meta_conversation_reply' => ['path' => '/meta/inbox/{conversationId}/reply', 'controller' => ConversationController::class.'::reply', 'method' => 'POST'],
            'mautic_meta_conversation_status' => ['path' => '/meta/inbox/{conversationId}/status', 'controller' => ConversationController::class.'::status', 'method' => 'POST'],
            'mautic_meta_job_retry' => ['path' => '/meta/operations/jobs/{jobId}/retry', 'controller' => OperationsController::class.'::retryJob', 'method' => 'POST'],
            'mautic_meta_job_cancel' => ['path' => '/meta/operations/jobs/{jobId}/cancel', 'controller' => OperationsController::class.'::cancelJob', 'method' => 'POST'],
            'mautic_meta_webhook_replay' => ['path' => '/meta/operations/webhooks/{eventId}/replay', 'controller' => OperationsController::class.'::replayWebhook', 'method' => 'POST'],
            'mautic_meta_adapter_retry' => ['path' => '/meta/operations/adapters/{deliveryId}/retry', 'controller' => OperationsController::class.'::retryAdapter', 'method' => 'POST'],
        ],
        'public' => [
            'mautic_meta_webhook' => ['path' => '/meta/webhook/{connectionId}', 'controller' => WebhookController::class.'::handle', 'method' => ['GET', 'POST']],
            'mautic_meta_adapter_reply' => ['path' => '/meta/adapters/{connectionId}/{adapterName}/messages', 'controller' => AdapterReplyController::class.'::reply', 'method' => 'POST'],
            'mautic_meta_landing_consent' => ['path' => '/meta/consent/landing/{connectionId}/{assetId}', 'controller' => LandingConsentController::class.'::capture', 'method' => 'POST'],
        ],
    ],
    'menu' => [
        'main' => [
            'mautic.meta.menu' => [
                'id'       => 'mautic_meta_root',
                'access'   => ['meta:connections:view', 'meta:templates:view', 'meta:messages:view'],
                'iconClass'=> 'ri-meta-fill',
                'priority' => 20,
                'children' => [
                    'mautic.meta.menu.overview' => ['route' => 'mautic_meta_dashboard', 'access' => 'meta:connections:view'],
                    'mautic.meta.menu.connections' => ['route' => 'mautic_meta_connections', 'access' => 'meta:connections:view'],
                    'mautic.meta.menu.templates' => ['route' => 'mautic_meta_templates', 'access' => 'meta:templates:view'],
                    'mautic.meta.menu.identities' => ['route' => 'mautic_meta_identities', 'access' => 'meta:messages:view'],
                    'mautic.meta.menu.inbox' => ['route' => 'mautic_meta_conversations', 'access' => 'meta:messages:view'],
                    'Meta operations' => ['route' => 'mautic_meta_operations', 'access' => 'meta:messages:view'],
                ],
            ],
        ],
    ],
];
