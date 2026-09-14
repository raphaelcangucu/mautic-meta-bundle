# Mautic Meta Bundle

Multi-account integration between Mautic 7 and the official Meta Graph API. WhatsApp, Instagram and Facebook/Messenger are independent channels backed by shared connections, encrypted credentials, assets, webhooks, queues, logs, and permissions.

## Interface atual — v0.12.1

Administração dos canais com o visual do Mautic: filtros, paginação no servidor, identificação por ícones e badges, formulários por canal e prévia de modelos WhatsApp.

![Visão geral do conector Meta no Mautic](docs/screenshots/visao-geral.png)

<details>
<summary>Conexões e contas</summary>

![Contas organizadas por canal, com situações alinhadas](docs/screenshots/conexoes.png)

</details>

<details>
<summary>Histórico de mensagens</summary>

![Mensagens com badges de canal, tipo e situação](docs/screenshots/mensagens.png)

</details>

Capturas reais da instalação de validação, em setembro de 2026. Consulte o [guia da interface](docs/INTERFACE.md) e o [histórico de versões](CHANGELOG.md).

O atendimento humano é um plugin separado: [Mautic Inbox Bundle](https://github.com/raphaelcangucu/mautic-inbox-bundle), compatível com esta versão. O conector administra autenticação, webhooks, identidades e envios; o Inbox administra as conversas e o trabalho dos atendentes.

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
- Connection diagnostics verify Instagram permissions and access to every configured Instagram profile.
- Business Manager Instagram asset IDs are resolved to canonical Instagram Graph IDs before profile, media, conversation, or messaging calls.
- Individually evidenced WhatsApp landing opt-ins are registered through one idempotent service, with immutable audit records and later opt-out precedence.
- Meta > Identities provides mandatory preview and confirmed historical synchronization directly from the persisted landing submission source.

## Instagram comment to private report reply

This flow is inactive until a Mautic campaign is published with an exact Instagram media ID and a nonempty private-reply message. No account, media ID, or report link is hard-coded.

1. Confirm that the Meta connection's signed webhook receives the Instagram `comments` field for the intended professional account, and that the account asset resolves to the webhook's canonical Instagram ID.
2. Create an unpublished Mautic campaign. Add **Instagram comment on a specific post** as its decision. Select the Instagram asset, enter the new publication's **Graph media ID** (not its URL), and set the whole-word keyword to `relatorio`. Matching ignores case and Portuguese accents, so `relatório` also matches.
3. On the decision's positive path, add exactly one terminal **Private reply to matching Instagram comment** action. The campaign must contain only these two events, with the decision at the root and no negative path. Enter the approved report text or link. The reply uses the event's comment ID; no contact field is needed. Mautic's action timing controls when the job is queued; use an immediate action for an immediate reply.
4. Review the campaign, then publish it only after the media ID and report destination are real and the webhook subscription has been verified. Run the existing Meta outbound queue worker. Test with a controlled comment before broader use.

Only published campaigns with a matching asset, exact media ID, and whole-word keyword evaluate the decision. A new commenter is associated with an anonymous Mautic contact and enrolled in the matching campaign. Mautic schedules and runs the positive-path action; that action creates a unique queue job per asset and comment. Repeated comments from the same contact require Mautic's campaign restart option. If the action fails after the decision log is stored, Mautic schedules that action for another attempt in five minutes; webhook replay leaves the rotation and existing action log intact. The unique queue key prevents a second job for the same comment. Ambiguous delivery outcomes are held for manual review instead of being retried automatically.

## Landing WhatsApp consent

Two independent consent sources are supported:

- `explicit_consent_fields`: individually persisted checkbox evidence.
- `mautic_api_waitlist`: contacts classified in the Waitlist stage or segment, backed by authenticated API-origin tracking for new contacts or an explicit administrator attestation for historical contacts.

The second mode reads Mautic's own `leads`, `stages`, `lead_lists`, and `lead_lists_leads` tables. It checks `phone` and `mobile`, does not modify contact names, never clears DNC/opt-out, and never sends a message. In **Meta > Identities**, select **Contatos Waitlist recebidos pela API do Mautic**, analyze, review every counter, and explicitly accept the displayed attestation before starting.

For MCP clients using the consent synchronization operations, the MetaBundle accepts the Waitlist mode through the existing compatibility fields:

```json
{
  "assetId": 2,
  "source": "mautic_api_waitlist",
  "consentVersion": "Waitlist",
  "batchSize": 100,
  "onlyUnsynced": true,
  "dryRun": true
}
```

At the MetaBundle service boundary these values are persisted as `sourceMode=mautic_api_waitlist` and `stage=Waitlist`.

Configure the **Landing consent evidence URL** and its HMAC secret on the Meta connection. The landing backend posts new consent events to:

```text
POST /meta/consent/landing/{connectionId}/{assetId}
X-Mautic-Meta-Timestamp: unix timestamp
X-Mautic-Meta-Signature: sha256=hex(HMAC-SHA256(timestamp + "." + exact JSON body, secret))
```

The same connection can read historical evidence from the configured HTTPS source. In the supplied landing backend the endpoint is `GET /api/internal/mautic/whatsapp-consents`; it signs `timestamp + "\n" + RFC3986 query string`. Both directions have a five-minute replay window and never log the secret.

Run these workers every minute:

```bash
php bin/console mautic:meta:consent:process --limit=100 --env=prod
php bin/console mautic:meta:consent-sync:process --env=prod
```

Historical backfill is dry-run by default and requires persisted source/version filters:

```bash
php artisan mautic:whatsapp-consent:backfill --source=lp_football --consent-version=football_weekly_report_v1
php artisan mautic:whatsapp-consent:backfill --source=lp_football --consent-version=football_weekly_report_v1 --checkpoint=0 --confirm
```

For trusted API/Waitlist synchronization, configure **Waitlist/API phone region** on the WhatsApp asset independently from the asset's own region. Brazilian imports may also enable the conservative legacy-mobile conversion, which only adds the ninth digit to a DDD plus eight-digit mobile candidate; short, ambiguous, fixed-line, and otherwise invalid values remain rejected for review.

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

## Native support inbox (0.12.0)

This release adds Facebook Messenger and Facebook feed comments alongside WhatsApp and Instagram. The optional MauticInboxBundle 1.0.0 provides the shared support interface; the connector continues to own channel credentials, identities, messages and outbound processing. Human takeover gates both direct and queued automation. Without the inbox bundle, the no-op integration preserves standalone operation.

Participant names, handles and profile images are enriched when the channel API and granted permissions allow it. Facebook Page permissions and webhook subscriptions must be configured for the intended messaging and comment features.

## Interface de administração

Consulte [Interface, filtros e validação](docs/INTERFACE.md) para a organização das páginas, paginação, editor visual de modelos e funcionamento da navegação no Mautic.
