# Changelog

## 0.8.4 - 2026-09-02

- Keep the identities controller compatible with workers holding the previous non-paginated route, preventing AJAX menu failures during deployment.

## 0.8.3 - 2026-09-02

- Add Mautic-style pagination and page-size controls to Meta identities.
- Add identity filters for free-text search, Meta asset, and consent status.
- Replace raw contact-ID editing with Mautic's asynchronous contact autocomplete.

## 0.8.2 - 2026-09-02

- Add the missing localized “Last activity” identity-list label.

## 0.8.1 - 2026-09-02

- Separate the trusted Waitlist/API import region from the Meta asset phone region.
- Normalize national imported numbers using the configured region before consent-sync validation.
- Optionally convert legacy Brazilian eight-digit mobile numbers by adding the mandated ninth digit.
- Safely reclassify existing trusted identities as updates when their normalized number changes.

## 0.8.0 - 2026-09-02

- Add authenticated Mautic contact-API origin tracking without relying on nullable creator fields.
- Automatically register API-imported Waitlist contacts against the active default WhatsApp asset.
- Add the independent `mautic_api_waitlist` synchronization source with mandatory administrator attestation.
- Preserve WhatsApp DNC and opt-outs, reject shared phones, and audit attestor, contact creation date, job and scope.
- Read Waitlist membership directly from Mautic stages and segments using both phone and mobile fields.

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
