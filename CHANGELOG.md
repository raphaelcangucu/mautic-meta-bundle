# Changelog

## 0.7.0 - 2026-09-02

- Add strict, idempotent and auditable WhatsApp landing consent registration with preserved evidence.
- Add a signed landing capture endpoint, durable retry queue, E.164 identity upsert and opt-out precedence.
- Add the `meta.whatsapp.register_opt_in` campaign action and approved-template send enforcement.
- Add two-step historical consent synchronization to Meta > Identities with progress, checkpoint, cancellation and rejection reports.
- Add an authenticated evidence-source bridge for the separately deployed landing backend.

## 0.6.2 - 2026-09-02

- Resolve Business Manager Instagram asset IDs to canonical Instagram Graph IDs through linked Page relationships or `ig_user_id` metadata.
- Remove the unsupported `user_id` field from Instagram profile reads.
- Use canonical IDs for Instagram profiles, media, conversations, and message sends.

## 0.6.1 - 2026-09-02

- Persist the connection `active`/`error` status and full diagnostic result.
- Verify the required Instagram permissions and access to every configured Instagram profile.
- Preserve Graph API error details and log method, safe endpoint, HTTP status, Meta code, and error subcode without tokens.

## 0.6.0 - 2026-09-02

- Add a native WhatsApp and Instagram conversation inbox with replies and status management.
- Add multiple signed omnichannel webhook adapters per Meta connection.
- Add durable adapter delivery, retry/backoff, idempotent events, and a single-worker lock.
- Add authenticated, signed, idempotent adapter-to-Mautic replies.
- Fix the Meta submenu, connection form persistence, and duplicate App ID/adapter validation.

## 0.5.0 - 2026-09-01

- Complete multi-account connection and asset CRUD, including encrypted credential rotation.
- Add automatic, ambiguity-safe inbound contact matching.
- Add operational message, queue, and webhook screens with manual recovery controls.
- Add queued WhatsApp media and interactive-message operations.
- Expand MCP support with connection/asset administration and live Instagram reads.

## 0.4.0 - 2026-09-01

- Add durable outbound queues, retry/backoff, rate limiting, diagnostics, migration, and initial MCP integration.
