# Mautic Meta Bundle

Multi-account integration between Mautic 7 and the official Meta Graph API. WhatsApp, Instagram and Facebook/Messenger are independent channels backed by shared connections, encrypted credentials, assets, webhooks, queues, logs, and permissions. Version 0.13.0 also provides an internal Tech Provider onboarding console for authorized customer WABAs.

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
- The Tech Provider console performs Embedded Signup, isolates each authorized company and validates WABA, phone, webhook, template and real test-delivery evidence.
- WhatsApp account screens expose operational health separately from Graph authorization and allow Business Profile fields and profile pictures to be updated through the official API.
- Brazilian mobile identifiers received with or without the ninth digit resolve to one canonical conversation without rewriting the exact recipient stored on each message.

## Tech Provider and Embedded Signup

Open **Meta > Tech Provider** to onboard an authorized company through Facebook Login for Business. The server exchanges the returned code, verifies the owning business and WABA, stores customer credentials encrypted and creates or updates only the assets returned for that company. Reauthorization is idempotent and an asset already owned by another connection is rejected instead of being moved silently.

After signup, use the same screen to validate the webhook subscription, phone registration, approved templates, consented test identity and final delivery state. An accepted API response is not presented as delivery: the operational status becomes healthy only from recent inbound evidence plus a `delivered` or `read` outbound webhook. Billing, business verification, display-name review and country restrictions remain Meta-side requirements. See [Tech Provider — implementação e homologação](TECH_PROVIDER.md) for rollout and recovery procedures.

## Canonical WhatsApp conversations

Meta can expose the same Brazilian mobile as either `55 + DDD + 8 digits` or `55 + DDD + 9 + 8 digits`. Incoming and outgoing messages now resolve both safe aliases before a conversation is created, then keep the conversation on the canonical E.164 recipient. The message log retains the exact delivery recipient for auditing. Existing duplicates can be previewed and consolidated with the Inbox command documented in `MauticInboxBundle` 1.0.13.

## Instagram comment to report replies

This flow is inactive until a Mautic campaign is published with an exact Instagram media ID and a nonempty private-reply message. No account, media ID, or report link is hard-coded.

1. Confirm that the Meta connection's signed webhook receives the Instagram `comments` field for the intended professional account, and that the account asset resolves to the webhook's canonical Instagram ID.
2. Create an unpublished Mautic campaign. Add **Instagram comment on a specific post** as its decision. Select the Instagram asset, enter the new publication's **Graph media ID** (not its URL), and set the whole-word keyword to `relatorio`. Matching ignores case and Portuguese accents, so `relatório` also matches.
3. On the decision's positive path, add a terminal **Private reply to matching Instagram comment** action. Enter the approved report text or link. The reply uses the event's comment ID; no contact field is needed. Mautic's action timing controls when the job is queued; use an immediate action for an immediate reply.
4. To acknowledge the request publicly, add **Public reply to matching Instagram comment** on the same positive path. Enter one reply variation per line. The action advances through the list for each queued public reply and reserves the next variation atomically so simultaneous comments do not receive the same position.
5. Review the campaign, then publish it only after the media ID and report destination are real and the webhook subscription has been verified. Run the existing Meta outbound queue worker. Test with a controlled comment before broader use.

Only published campaigns with a matching asset, exact media ID, and whole-word keyword evaluate the decision. A new commenter is associated with an anonymous Mautic contact and enrolled in the matching campaign. Mautic schedules and runs each positive-path action, creating a unique private and public queue job per asset and comment. Repeated comments from the same contact require Mautic's campaign restart option. If an action fails after the decision log is stored, Mautic schedules that action for another attempt in five minutes; webhook replay leaves the rotation and existing action log intact. The unique queue keys prevent duplicate replies to the same comment. Ambiguous delivery outcomes are held for manual review instead of being retried automatically.

### Resolve an Instagram permalink

An OAuth2 client with `meta:connections:view` permission can resolve an Instagram post or reel permalink to the exact Graph media ID owned by a configured professional-account asset:

```bash
curl --get \
  --header "Authorization: Bearer $MAUTIC_ACCESS_TOKEN" \
  --data-urlencode "permalink=https://www.instagram.com/p/DdRsy0CgJk7/" \
  https://mautic.example.com/api/meta/instagram/assets/4/media/resolve
```

The read-only endpoint accepts `/p/{shortcode}/` and `/reel/{shortcode}/` URLs. It removes the query string, fragment, optional `www`, and trailing slash for matching, then searches a bounded number of pages from the connected account's own media edge. A successful response contains `asset_id`, `account`, `media_id`, canonical `permalink`, `media_type`, and `timestamp`. A media-not-found response uses HTTP 404 with `code=instagram_media_not_found` and `retryable=true`, so callers can safely retry newly created publications. Responses never contain Meta credentials.

## WhatsApp contactability

Landing pages and CRM signup collect the phone number. Campaigns send to contacts who are in the source segment and are **not** on Mautic Do Not Contact for the `whatsapp` channel.

Inbound "stop" / opt-out keywords still mark the Meta identity as opted out **and** add DNC `whatsapp`. There is no separate per-number opt-in table or landing-consent sync. `require_opt_in` on WhatsApp assets is gone.

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

## Sending limits and service-window replies

Every outbound path (campaigns, queue, UI, and MCP) is checked immediately before it calls Meta. Proactive WhatsApp and Instagram sends retain the configured local volume and cooldown controls. A free-form WhatsApp reply tied to a verified inbound message in the current 24-hour customer-service window is dispatched immediately and is not delayed by the proactive-send cooldown. Outside that window, an approved template is required. Consent, DNC, idempotency, human takeover, Meta account tier, quality controls and API limits continue to apply.

## Legacy migration

The old plugin did not store the Meta App ID, so provide it explicitly:

```bash
php bin/console mautic:meta:migrate-whatsapp --app-id=YOUR_META_APP_ID --dry-run
php bin/console mautic:meta:migrate-whatsapp --app-id=YOUR_META_APP_ID
```

Disable the old plugin only after verifying the migrated connection, WABA, phone number, webhook, and campaign actions.

## MCP

With `MauticMcpBundle` 0.15.1 or newer enabled, the same services are exposed as `mautic_read_meta`, `mautic_send_meta_message`, and `mautic_manage_meta`.

## Consent behavior

WhatsApp phone assets require an explicit opt-in by default. This can be disabled per phone asset only for installations that manage lawful consent externally. `STOP`, `PARAR`, `SAIR`, `CANCELAR`, and `DESCADASTRAR` mark the identity opted out and add a WhatsApp DNC entry to the linked Mautic contact. Opt-in keywords restore only a user-created unsubscribe; they do not override a bounce or an administrator block.

## Credits

The Instagram architecture is informed by [OpenReply](https://github.com/diwenne/openreply), created and maintained by [Diwen Huang](https://github.com/diwenne), and its upstream project `instagram-comment-to-dm`. OpenReply is MIT licensed. This plugin is a PHP/Symfony implementation for Mautic and does not embed the OpenReply Next.js application.

## License

GPL-3.0-or-later.
