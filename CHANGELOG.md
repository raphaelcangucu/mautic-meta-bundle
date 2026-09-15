# Changelog

## Unreleased

- Add idempotent public replies to Instagram comment campaigns with sequential message rotation.
- Allow a public comment reply and a private Direct reply to run together without triggering the local cooldown between different reply types.

## 0.13.0 - 2026-09-15

- Add the internal Tech Provider console and Embedded Signup flow with company, WABA, phone-number and token isolation.
- Add operational WhatsApp diagnostics based on real inbound, outbound and delivery evidence instead of authorization alone.
- Add WhatsApp Business Profile editing and the official resumable-upload flow for profile pictures.
- Dispatch support and AI service-window replies immediately while retaining consent, DNC, idempotency and channel-policy checks.
- Resolve Brazilian WhatsApp recipients with or without the mobile ninth digit to one canonical conversation.
- Preserve exact message recipients for delivery auditing while preventing future duplicate conversation rows.

## 0.12.2 - 2026-09-14

- Canonicalize inbound Brazilian WhatsApp identifiers before contact and identity matching when Meta omits the mobile ninth digit.
- Preserve the existing identity, opt-in state and contact timeline instead of creating an unlinked duplicate.

## 0.12.1 - 2026-09-13

- Redesign Meta administration with Mautic colors, shared navigation and channel/status/type badges.
- Add server-side filters and pagination for account, template, identity and operations lists.
- Add channel-specific forms and a safe WhatsApp template preview that preserves advanced components.
- Keep list navigation and account context stable across forms and synchronizations.

## 0.12.0 - 2026-09-13

- Add Facebook Messenger and feed comment processing alongside WhatsApp and Instagram.
- Integrate the optional native support inbox with human takeover checks for direct and queued automation.
- Resolve participant names, handles and profile images with cached, non-blocking lookups.
- Route human WhatsApp replies using the conversation identity and retain uncertain sends for review.

## 0.10.5 - 2026-09-13

- Add an optional support-inbox boundary with a standalone no-op implementation.
- Guard queued and direct Graph sends atomically when human takeover is active.
- Hold transport-timeout outcomes for review instead of retrying a possibly accepted send.
- Keep Instagram public comments in distinct conversations keyed by the exact comment.

## 0.10.4 - 2026-09-03

- Recognize an inbound WhatsApp service window by linked Mautic contact when Meta's canonical `wa_id` differs from the submitted E.164 number.

## 0.10.3 - 2026-09-03

- Add a secure interactive command to register configured WhatsApp phone assets with the Cloud API without persisting or logging the two-step verification PIN.

## 0.10.2 - 2026-09-02

- Accept a verified signed callback from the configured WABA as runtime evidence of both app association and the WhatsApp webhook subscription when Meta's `subscribed_apps` read is empty.

## 0.10.1 - 2026-09-02

- Retry webhook events left in `received` when a request stops after durable ingestion but before processing.
- Apply the real WhatsApp delivery failure to the message instead of leaving it accepted indefinitely.

## 0.10.0 - 2026-09-02

- Require and persist `response.messages[0].id` before completing any WhatsApp job.
- Persist sanitized Meta response/error diagnostics, message status, and wamid.
- Validate all configured Instagram and WhatsApp assets, approved welcome template, app/WABA subscription, webhook subscription, phone registration, and channel permissions.
- Rotate the exposed landing consent secret and verify the signed webhook callback end to end.

## 0.9.0 - 2026-09-02

- Add channel, asset, consent, and free-text identity filters.
- Add single and bulk identity removal with confirmation and CSRF protection.
- Archive removed identities so historical consent evidence remains intact.

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
