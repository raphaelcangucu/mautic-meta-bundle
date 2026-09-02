# Mautic Meta Bundle

Multi-account integration between Mautic 7 and the official Meta Graph API. WhatsApp and Instagram are independent channels backed by shared connections, encrypted credentials, assets, webhooks, queues, logs, and permissions.

## Implemented

- Multiple Meta app connections.
- Complete connection and asset create/edit/delete screens, with optional encrypted credential rotation.
- Multiple WABAs, phone numbers, Instagram accounts, and Facebook pages per connection.
- Credentials encrypted with Mautic's native encryption helper.
- Connection-scoped official Graph API client.
- Per-connection signed webhook URL and verification challenge.
- Idempotent webhook persistence.
- Mautic menu, overview dashboard, connections screen, and granular permissions.
- WhatsApp text, media, interactive, and official template delivery.
- WhatsApp template synchronization, creation, update, and deletion services.
- Instagram profile, media, comments, private/public replies, direct messages, inbox, and insights.
- Native campaign actions for WhatsApp and Instagram with an explicit sending asset.
- Delivery and inbound message logs, including WhatsApp status callbacks.
- Contact identities per Meta asset, manual contact association, consent audit fields, and last interaction.
- Exact opt-in/opt-out keyword handling in English and Portuguese.
- WhatsApp DNC and opt-in enforcement before every send; Instagram DNC enforcement before outbound actions.
- An operational identity/consent screen protected by granular message permissions and CSRF.
- Contact timeline entries for linked WhatsApp and Instagram activity.
- Real-time campaign decisions filtered by channel, direction, message type, delivery status, and inbound text.
- Visual create, edit, delete, and synchronize workflows for official WhatsApp templates.
- Durable database-backed outbound queue with configurable attempts, exponential backoff, stalled-job recovery, and a single-worker advisory lock.
- Per-connection token-bucket rate limiting and Graph API diagnostics.
- Mandatory local anti-spam guard with conservative initial caps per asset and recipient, cooldowns, and WhatsApp's 24-hour customer-service window.
- Failed webhook audit state with safe retry of previously failed event IDs.
- Operational message, queue, and webhook screen with manual retry, cancellation, and replay controls.
- Conservative automatic inbound contact matching: configurable exact-field lookup and unique normalized phone fallback for WhatsApp.
- A migration command for the legacy single-account `MauticWhatsAppBundle`.
- Optional MCP integration with dedicated read, send, and administration tools.
- Multiple signed omnichannel webhook adapters per Meta connection, filtered by event and channel.
- Native WhatsApp/Instagram conversation inbox with history, unread state, replies, and workflow status.

## Omnichannel webhook adapters

Edit a Meta connection and add one or more adapters as JSON:

```json
[
  {
    "name": "Omnichannel",
    "url": "https://inbox.example.com/webhooks/mautic-meta",
    "secret": "replace-with-a-long-random-secret",
    "enabled": true,
    "allowReplies": true,
    "events": ["message.received", "message.sent", "message.delivered", "message.read", "message.failed"],
    "channels": ["whatsapp", "instagram"],
    "timeout": 5,
    "maxAttempts": 5
  }
]
```

Adapter names must be unique inside a connection. Secrets are encrypted at rest and rendered back as `***`. Deliveries include a stable event ID, timestamp, and `X-Mautic-Meta-Signature` computed as `sha256=HMAC_SHA256(timestamp + "." + rawBody, secret)`. One failing destination never blocks Meta webhook processing or another destination.

When `allowReplies` is enabled, the adapter can enqueue a reply at:

```text
POST /meta/adapters/{connectionId}/{urlEncodedAdapterName}/messages
```

The request body must contain `conversationId`, `text`, and an `idempotencyKey`. Sign the exact raw JSON body with the same timestamp/signature headers used for outbound events. Timestamps older than five minutes are refused, and repeated idempotency keys return the original queue job.

## Queue worker

Run the worker every minute. Multiple invocations are safe because it uses a database advisory lock:

```bash
php bin/console mautic:meta:queue:process --limit=100 --env=prod
php bin/console mautic:meta:adapters:process --limit=100 --env=prod
```

Campaign actions queue messages by default. Permanent validation, consent, and DNC failures are not retried; rate-limit and server failures use exponential backoff.

## Conversation inbox

Open **Meta > Meta inbox** to read WhatsApp and Instagram conversations, filter by channel/status, mark a thread as open, pending, resolved, or archived, and queue a reply. Incoming messages reopen the conversation and increase its unread count; opening the thread marks it as read.

## Safe initial sending limits

Every outbound path (campaigns, queue, UI, and MCP) is checked immediately before it calls Meta. WhatsApp starts at 250 messages per asset/24h, 50/hour, 3 per recipient/24h, and a 60-second recipient cooldown. Instagram starts at 50 messages per asset/24h, 20/hour, 3 per recipient/24h, and a 5-minute recipient cooldown. These values are visible and may be lowered on each asset's create/edit screen; they cannot be raised beyond the conservative safety ceilings in this release. WhatsApp free-form content is blocked outside the 24-hour customer-service window; an approved template is required. Meta's account tier, quality controls, recipient consent, DNC, and API limits still apply and may be stricter.

## Legacy migration

The old plugin did not store the Meta App ID, so provide it explicitly:

```bash
php bin/console mautic:meta:migrate-whatsapp --app-id=YOUR_META_APP_ID --dry-run
php bin/console mautic:meta:migrate-whatsapp --app-id=YOUR_META_APP_ID
```

Disable the old plugin only after verifying the migrated connection, WABA, phone number, webhook, and campaign actions.

## MCP

With `MauticMcpBundle` 0.8 or newer enabled, the same services are exposed as `mautic_read_meta`, `mautic_send_meta_message`, and `mautic_manage_meta`.

## Consent behavior

WhatsApp phone assets require an explicit opt-in by default. This can be disabled per phone asset only for installations that manage lawful consent externally. `STOP`, `PARAR`, `SAIR`, `CANCELAR`, and `DESCADASTRAR` mark the identity opted out and add a WhatsApp DNC entry to the linked Mautic contact. Opt-in keywords restore only a user-created unsubscribe; they do not override a bounce or an administrator block.

## Credits

The Instagram architecture is informed by [OpenReply](https://github.com/diwenne/openreply), created and maintained by [Diwen Huang](https://github.com/diwenne), and its upstream project `instagram-comment-to-dm`. OpenReply is MIT licensed. This plugin is a PHP/Symfony implementation for Mautic and does not embed the OpenReply Next.js application.

## License

GPL-3.0-or-later.
