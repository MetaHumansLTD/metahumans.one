# Meet Flow Runbook (PlugNMeet + Personas + Tenant Isolation)

## Scope
This runbook defines the target architecture and an incremental implementation plan for a persona-driven meeting system on MetaHumans, using PlugNMeet + LiveKit and the existing CUE framework.

Goals:
- Persona-authenticated booking, joining, sharing, and retrieval of meetings.
- Calendar per user/tenant, with reschedule/cancel and reminders.
- Agenda governance: series agendas, carry-over of open items, minutes, decisions, action items.
- Voting/polling integrated with shareholder voting rights (equity holdings).
- Secure storage of recordings/transcripts/agenda artifacts in tenant-isolated block storage.
- Ingestion of meeting artifacts and AI summaries into long-term memory.

Non-goals (initially):
- Public meetings with anonymous participants (requirements state users must be logged in).

## Current Implementation Status (as of 2026-04-13)
Completed:
- Hub meetings module: `/hub/meetings/` (meetings list, series, join/share, reschedule/cancel, schedule modal).
- Hub calendar: `/hub/calendar/` (month grid, editable events, schedule meeting modal).
- Agendas:
  - DB storage + tenant JSON mirror.
  - Carry-over across series (open items roll forward).
  - Hub UI:
    - `/hub/meetings/agenda.php` (edit agenda + minutes, series selector, templates).
    - `/hub/meetings/agendas.php` (list/edit/delete).
  - Document editor: TinyMCE served locally from `/gear/editors/TinyMCE/`.
- Artifacts + memory:
  - Recording ingest job imports PlugNMeet recordings/subtitles into tenant storage.
  - Artifact pipeline job generates transcripts (from VTT when available; otherwise ASR) and meeting summaries; indexes summaries; ingests summaries into long-term memory.
  - Hub UI: `/hub/meetings/recordings.php` (download/delete recordings + artifacts, search summaries).
- Voting:
  - Hub UI:
    - `/hub/meetings/vote.php` (create/cast/close/export + audit).
    - `/hub/meetings/votes.php` (list).
  - Equity snapshot semantics at ballot time; default weight=1 if no equity is issued.

Still pending / needs confirmation:
- Hermes/Tock: confirmed execution surface + strict tenant/persona/meta_human isolation enforcement for reminders/invites.
- Governance finalization: tamper-proof minutes/protocol export (PDF) and immutable signing workflow.
- Translations: automatic translation generation when PlugNMeet does not provide subtitle tracks.

Voting policy (confirmed):
- Ordinary voting is computed from equity user type (not equity class):
  - founders: 1 share = 1000 votes
  - shareholders: 1 share = 1 vote
  - mvi: 0 votes (ineligible)
  - source: `/control/digital-equity-management.php` user profile (`equity_user_profiles.user_type` + votes fields)

## Verification Checklist (End-to-End)
Prerequisites:
- You must be logged in (Hub pages are authenticated).
- Tenant context must be set (`$_SESSION['mh_tenant_id']`), otherwise meeting lists may be empty or cross-context.

Phase 1 (Harden + unify core booking/billing):
1. Booking UI:
   - Visit: `/booking.php`
   - Create a meeting and confirm the result shows:
     - Host join link
     - Invite participants modal (share UI)
     - Agenda link
2. Billing runner:
   - Visit: `/gear/sync/index.php`
   - Confirm job exists and can run:
     - `meeting_billing`

Phase 2 (Hub integration):
1. Meetings module:
   - Visit: `/hub/meetings/`
   - Validate:
     - Upcoming / Past / All filters show meetings from `mh_meetings`
     - Row actions: Join, Share, Agenda, Votes, Artifacts, Reschedule, Cancel
2. Series:
   - Visit: `/hub/meetings/?tab=series`
   - Create a series, refresh, confirm it persists
   - Delete a series you own, confirm it disappears and meetings become unassigned
3. Schedule from meetings:
   - In `/hub/meetings/`, click Schedule, create a meeting, confirm it appears in the list after refresh

Phase 3 (Agendas):
1. Agenda editing:
   - Visit: `/hub/meetings/agendas.php`
   - Open any agenda and confirm:
     - Delaware fields
     - Series selector
     - Agenda items table
     - Document editor + Minutes editor
2. Carry-over across series:
   - Create two meetings in the same series
   - Add an “open” agenda item to the first meeting
   - Open the second meeting agenda and confirm the open item carried over
3. Templates:
   - In an agenda page, enter “Save as default” name and Save template
   - Insert that template into another meeting and confirm document/items/fields update

Phase 4 (Artifacts + memory):
1. Recording ingest:
   - Visit: `/gear/sync/index.php`
   - Run/enable: `meeting_ingest`
   - Then visit: `/hub/meetings/recordings.php` and confirm recordings appear under the correct room
2. Transcript + summary pipeline:
   - Visit: `/gear/sync/index.php`
   - Run/enable: `meeting_artifacts`
   - Then visit: `/hub/meetings/recordings.php`
   - Confirm each recording shows:
     - Subtitles (if PlugNMeet provided)
     - Transcript (`*_auto.txt`)
     - Summary (`*_summary.json` / `*_summary.md`)
3. Summary search:
   - Visit: `/hub/meetings/recordings.php`
   - Search for a keyword from a known meeting summary and confirm results appear

Phase 5 (Voting):
1. Create + vote:
   - Visit: `/hub/meetings/votes.php` (list) then open a meeting’s vote page
   - Create a vote (meeting owner)
   - Cast ballots (different users if available)
2. Close + export:
   - Close the vote (meeting owner)
   - Download export and confirm it includes:
     - Results
     - Ballot weights
     - Per-ballot snapshot JSON
     - Export sha256 recorded in DB

## Current Code Inventory (Usable vs Deprecate)

### Usable (keep + extend)
- [/booking.php](file:///home/onemeta/public_html/booking.php)
  - Legacy shim that includes the real booking implementation.
- [/gear/meet/booking.php](file:///home/onemeta/public_html/gear/meet/booking.php)
  - Web booking/join UI; enforces login, schedules token billing, stores meeting metadata via calendar helpers, and renders the invite/share UX in a modal.
- [/meet.php](file:///home/onemeta/public_html/meet.php)
  - Redirect shim to booking UI.
- Invite/share UX
  - Rendered inside booking’s “Invite participants” modal (presenter/participant links + QR + share buttons).
- Hub meetings module
  - [/hub/meetings/index.php](file:///home/onemeta/public_html/hub/meetings/index.php)
  - [/hub/meetings/api.php](file:///home/onemeta/public_html/hub/meetings/api.php)
  - [/hub/meetings/agenda.php](file:///home/onemeta/public_html/hub/meetings/agenda.php)
  - [/hub/meetings/agendas.php](file:///home/onemeta/public_html/hub/meetings/agendas.php)
  - [/hub/meetings/vote.php](file:///home/onemeta/public_html/hub/meetings/vote.php)
  - [/hub/meetings/votes.php](file:///home/onemeta/public_html/hub/meetings/votes.php)
  - [/hub/meetings/recordings.php](file:///home/onemeta/public_html/hub/meetings/recordings.php)
- Hub calendar
  - [/hub/calendar/](file:///home/onemeta/public_html/hub/calendar/index.php)
- [/gear/calendar/calendar_helpers.php](file:///home/onemeta/public_html/gear/calendar/calendar_helpers.php)
  - Meeting persistence (`mh_meetings`), schema creation, reminder plumbing hooks.
- [/gear/settings/functions/database-context.php](file:///home/onemeta/public_html/gear/settings/functions/database-context.php)
  - Tenant/context aware DB routing.
- [/auth/tenant_provisioning.php](file:///home/onemeta/public_html/auth/tenant_provisioning.php)
  - Tenant storage base (`/data/tenants/<tenantSafe>`), deprovisioning patterns.
- [/hub/meeting.php](file:///home/onemeta/public_html/hub/meeting.php)
  - Deprecated in practice (redirects into `/hub/meetings/`).
- [/hub/equity/manage.php](file:///home/onemeta/public_html/hub/equity/manage.php)
  - Equity holdings (basis for voting weight).
- Jobs / cron orchestration
  - [/gear/sync/index.php](file:///home/onemeta/public_html/gear/sync/index.php) (meeting_billing, meeting_ingest, meeting_artifacts)
  - [/gear/meet/cron/meeting-billing.php](file:///home/onemeta/public_html/gear/meet/cron/meeting-billing.php)
  - [/gear/meet/cron/meeting-ingest.php](file:///home/onemeta/public_html/gear/meet/cron/meeting-ingest.php)
  - [/gear/meet/cron/meeting-artifacts.php](file:///home/onemeta/public_html/gear/meet/cron/meeting-artifacts.php)

### Needs hardening (keep, refactor)
- [/gear/meet/meet_helpers.php](file:///home/onemeta/public_html/gear/meet/meet_helpers.php)
  - Contains meeting create + join token logic, but requires security hardening:
    - Remove credential fallbacks (accept only encrypted config from `/data/config/plugnmeet.json`).
    - Ensure no secrets are accepted from legacy keys (`api_key`/`secret`) once production is locked down.

### Deprecate (do not use for production)
- [/gear/meet/meet.php](file:///home/onemeta/public_html/gear/meet/meet.php)
  - Contains hardcoded secrets; overlaps with helpers. Replace with hardened meeting API.
- [/gear/studio/meeting_join.php](file:///home/onemeta/public_html/gear/studio/meeting_join.php)
  - Hardcoded secrets and weak token model. Replace with hardened join endpoint or remove.
- [/gear/auto-agenda/auto-agenda.php](file:///home/onemeta/public_html/gear/auto-agenda/auto-agenda.php)
  - Prototype logic uses inconsistent session keys and non-tenant storage. Replace with v2 agenda module.
- [/gear/auto-agenda/agenda.php](file:///home/onemeta/public_html/gear/auto-agenda/agenda.php)
  - UI prototype; can be reused only after agenda storage is redesigned.

## Global UI Integration Requirement
All meet-related pages must use Global UI includes explicitly:
- In `<head>`: `include_once getTemplatesPath() . '/global-ui/includes/complete-head.php';`
- After `<body>`: `include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php';`
- Before `</body>`: `include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php';`

Auto-injection is disabled in CUE core: [/\.cue/core.php](file:///home/onemeta/public_html/.cue/core.php)

## Entities & Data Model

### Tenant identity
- `tenant_id`: canonical string, e.g. `user:<username>` and `persona:<personaNameOrId>`
- `tenant_safe`: filesystem-safe mapping (use `mh_tenant_safe()` when available)

### Meeting
Minimum fields (DB row + metadata file):
- `meeting_id` (internal UUID) and/or `room_id` (PlugNMeet room id)
- `title`
- `created_by_user`
- `scheduled_for_utc` (optional)
- `invite_url`, `presenter_join_url`, `participant_join_url`
- `series_id` (optional; for recurring meetings)
- `status`: scheduled | live | ended | canceled

### Meeting series
For recurring governance meetings (Dev/Board/Shareholders):
- `series_id`
- `name`
- `default_attendees`
- `agenda_template_id`
- `voting_rules_id`

### Agenda
Agenda is per meeting and can be derived from previous meeting in series:
- `meeting_id`
- `items[]` with type: info | action | decision | vote
- `status`: open | resolved | approved
- `carry_over_from_meeting_id`
- `document_md` (rich agenda document edited in Hub UI)
- `delaware{...}` (structured governance fields used to generate consistent agendas/minutes)
- Templates (per tenant DB + tenant filesystem mirror):
  - `mh_meeting_agenda_templates` (name + template_json)

### Artifacts
Stored tenant-isolated:
- recordings (cloud/local)
- transcripts (+ translations when provided by PlugNMeet; automatic translation generation still pending)
- AI summary + decisions/action items
- exported PDF minutes/protocol

## Storage Layout (Block Storage)
Root: `/data/tenants/<tenant_safe>/meetings/<room_id>/`
- `meeting.json`
- `agenda/agenda.json`
- `minutes/minutes.md` (HTML may also be stored in DB; PDF export pending)
- `recordings/…`
- `transcripts/…`
- `summaries/summary.json`
- `votes/…`

This storage is the system-of-record for user-owned meeting artifacts; DB holds indexes for listing/search.

## Authentication & Authorization
Baseline:
- All flows require login (session-based: `$_SESSION['mh_auth_user']`).
- Presenter/host actions require:
  - ownership (created_by_user) or explicit role membership for a series.
  - token charge eligibility (only charged once per meeting creation).

Voting:
- Vote eligibility and weight derived from equity holdings.
- Minimum: per-user weight = shares held at vote creation time.

## Token Economics (Meeting Cost)
Requirement: 50 tokens per meeting, unlimited time/users.
- Charge once only if the meeting runs for 5 minutes with participants (delayed billing runner).
- Never charge on subsequent joins or for participants.
- Reject creation if user balance < 50.

Implementation:
- Scheduler checks token balance using tokenomics and session/biometrics fallback (never email).
- Billing is executed by the delayed billing runner after the 5-minute participation rule is satisfied.

## Notification, Reminders, Hermes/Tock
Target:
- On create/reschedule/cancel: notify attendees + create/update reminder schedule.
- Delivery: Hermes for messaging; Tock for schedule execution.

Execution surface (confirmed):
- `https://meta.superhumans.one/tock/…` is served by nginx and reverse-proxied into the `tock-router` FastAPI app running in the `cortex-core` Kubernetes namespace.
- `https://meta.superhumans.one/hermes/…` is served by nginx and reverse-proxied into the `hermes4-405b-api` FastAPI proxy running in the `triton` Kubernetes namespace.

Current available hook:
- `calendar_notify_tock()` (calendar helpers) sends an HTTP POST to `/tock/v1/route`.
- Isolation enforcement is mandatory:
  - `tock-router` rejects requests missing/invalid `tenant_id`, `persona_id`, or `meta_human_id`.
  - Caller must provide identifiers per `/gear/settings/id_identifiers.php` (do not use session_id as an isolation boundary).
  - `tock-router` also requires an HMAC signature so only authorized callers (metahumans.one) can route jobs:
    - Headers: `X-MH-KeyId`, `X-MH-Timestamp`, `X-MH-Signature`
    - Signature: `HMAC_SHA256(secret, timestamp + "\\n" + raw_body)`
    - Timestamp window: ±120s
  - Cross-checks: `tenant_id` must match `user_id` and `persona_id/meta_human_id` must match `user_id` (prevents spoofing another user/tenant).

## End-to-End Flows

### Flow A: Web booking (UI)
1. User visits `/hub/meet` (client entrypoint) which routes into the booking flow.
2. Enters meeting name, date/time, invitees.
3. System:
   - schedules a 50 token charge (once) only if the meeting runs for 5 minutes with participants.
   - creates PlugNMeet room (idempotent).
   - stores meeting row + tenant meeting.json.
4. User is shown:
   - host join link
   - invite join link
   - invite/share modal (presenter + participant links, QR, share buttons)
5. Invite delivery: Hermes/Tock (integration surface to be confirmed). No email delivery is used.

### Flow B: Persona/IDE booking (API)
1. Headless IDE issues JSON request (internal endpoint).
2. Endpoint orchestrates:
   - auth as persona/user
   - token charge
   - room create
   - meeting store
   - invites/reminders enqueue
3. Returns: meeting ids, join links, share link, calendar ids.

### Flow C: Join
1. Attendee opens invite link (`/meet.php?room_id=...&role=viewer`).
2. Redirect to booking flow; user logs in if required.
3. System generates join token (role-based).
4. Redirect to `/meet/?access_token=...`.

### Flow D: Record → Transcribe → Summarize → Store
1. Recording enabled in PlugNMeet.
2. On recording complete:
   - artifact file saved to tenant storage
   - transcription job created (if PlugNMeet does not provide subtitle tracks; ASR-based)
   - translation job created (pending, only if required; currently relies on PlugNMeet-provided subtitle tracks)
   - AI summary + action/decision extraction job created
3. Store results under tenant meeting folder and index in DB.

### Flow E: Agenda governance (Series)
1. Creating a meeting in a series auto-populates agenda:
   - open items from last meeting + template items
2. During meeting: minutes are written per agenda item.
3. After meeting: finalize generates tamper-proof PDF + stores (pending).
4. Open items roll over to next meeting.

### Flow F: Voting/polls
1. Create a vote attached to agenda item.
2. Eligible voters = shareholders (equity holdings).
3. Weight = shares at snapshot time.
4. Record results to tenant storage + DB; expose on meeting recap.

## Implementation Plan (Phased)

### Phase 1 (Harden + Unify)
Completed:
- Booking inversion (`/booking.php` shim → `/gear/meet/booking.php` canonical).
- Global UI integration on Hub pages.
- Billing runner implemented and managed by Sync Manager.
Still pending:
- Remove PlugNMeet credential fallbacks from `meet_helpers.php` and require encrypted config only.

## Canonical cron install
- `*/1 * * * * root /usr/local/bin/php /home/onemeta/public_html/gear/meet/cron/meeting-billing.php >/dev/null 2>&1`
- `*/2 * * * * root /usr/local/bin/php /home/onemeta/public_html/gear/meet/cron/meeting-ingest.php >/dev/null 2>&1`
- `*/3 * * * * root /usr/local/bin/php /home/onemeta/public_html/gear/meet/cron/meeting-artifacts.php >/dev/null 2>&1`

Cron can also be managed via the Sync Manager UI:
- `/gear/sync/index.php` (jobs: `meeting_billing`, `meeting_ingest`, `meeting_artifacts`)

## Client vs Processing Placement
- Client entrypoints should be served under `/hub/meet` (Hub UX, authenticated).
- Processing modules and APIs should live under `/gear/meet` and `/gear/calendar`.
- Root-level legacy entrypoints (`/meet.php`, `/booking.php`) should become thin shims only.

## Calendar storage (where user calendars live)
- Meetings are stored in the tenant-scoped database selected by `mh_apply_tenant_context()` during login/registration (DB-per-tenant).
- The calendar tables are created on-demand inside each tenant DB:
  - `mh_meetings` (meeting index + billing status)

## User calendar access
- Users view/edit their calendar in Hub:
  - `/hub/calendar/` (month grid with modal reschedule/edit/delete + schedule meeting)
- Internal gear calendar UI remains internal and should not be linked from Hub:
  - `/gear/calendar/`

## Duplication Policy
- `/hub/meeting.php` should be deprecated once `/hub/meet` provides:
  - meeting list (upcoming/past)
  - join/share actions
  - reschedule/cancel actions
  - agenda access

### Phase 2 (Hub integration)
Completed:
- `/hub/meetings/` provides:
  - upcoming/past/all list from `mh_meetings`
  - series list + delete (owner-only) + per-series meeting filter
  - join/share actions per row (share uses the legacy share UI)
  - reschedule/cancel actions per row
  - schedule meeting modal integrated into `/hub/meetings/`

### Phase 3 (Agendas)
Completed:
- DB tables + tenant JSON mirror.
- Carry-over logic across series.
- Hub UI for agendas:
  - `/hub/meetings/agenda.php` (agenda + minutes + templates + series selector; TinyMCE editor)
  - `/hub/meetings/agendas.php` (list/edit/delete)

### Phase 4 (Artifacts + memory)
Completed:
- `meeting-ingest` imports recordings/subtitles into tenant storage.
- `meeting-artifacts` generates transcript + summary for each recording, indexes summaries, and ingests into long-term memory.
Still pending:
- Automatic translation generation when PlugNMeet does not provide subtitle tracks.

### Phase 5 (Voting)
Completed:
- Equity snapshot captured at ballot time.
- Weighted voting: shares basis is supported; ordinary voting uses user type rules (founder=1000 votes/share, shareholder=1 vote/share, mvi=0 votes).
- Hub UI + audit log + immutable export:
  - `/hub/meetings/vote.php`
  - `/hub/meetings/votes.php`

## Open Questions (must be confirmed)
1. Hermes/Tock isolation boundary: every job payload must include `tenant_id`, `persona_id`, and `meta_human_id` (see `/gear/settings/id_identifiers.php`) and execution must enforce tenant-scoped storage + tenant-scoped DB routing.
2. Hermes/Tock execution surface on superhumans.one: confirmed HTTP via nginx reverse proxy into Kubernetes services (`tock-router` and `hermes4-405b-api`).
3. Voting rule (equity-linked): votes/polls attach to `/hub/equity/manage.php` and `/control/digital-equity-management.php` holdings; weight is derived from the participating user; if no equity is issued then weight defaults to `1`. Ordinary voting uses user type rules (founder=1000 votes/share, shareholder=1 vote/share, mvi=0 votes).

## Tock Integration Requirements (Read This Before Calling Tock)
Reference:
- CUE autoload + integration standards: `/.cue/core.php`
- Identifier standards: `/gear/settings/id_identifiers.php`

Execution surface:
- Tock router URL: `https://meta.superhumans.one/tock/v1/route`
- The router is served by nginx and reverse-proxied to the `tock-router` FastAPI app in Kubernetes (`cortex-core` namespace).

Strict isolation boundary (mandatory):
- Only metahumans.one is allowed to call Tock.
- Every call MUST include:
  - `tenant_id` (must be `user:<user_id>`)
  - `persona_id` (must be `MH-<user_id>`)
  - `meta_human_id` (must be `meta:MH-<user_id>`)
  - `user_id`
- Every call MUST include signature headers:
  - `X-MH-KeyId`
  - `X-MH-Timestamp`
  - `X-MH-Signature` = `HMAC_SHA256(secret, timestamp + "\\n" + raw_body)`
  - Timestamp window: ±120 seconds
- Router rejects any request missing/invalid identifiers, signatures, or mismatched identity linkage.

Local helper:
- Use: `/gear/brain/tock-call.php` (CLI example and helper implementation).
- Signing config file: `/data/config/tock-signing.json` with keys:
  - `key_id`
  - `secret`
Status:
- Completed and deployed: `tock-router` enforces signatures and identity linkage; metahumans.one callers must sign requests.



## Agenda “inside PlugNMeet” + chair → presenter handover (current state + what’s required)
How the agenda is currently shown

- The agenda UI is a Hub page: /hub/meetings/agenda.php?id=<meeting_id> and is not embedded into the PlugNMeet React UI.
- During a meeting, presenters currently:
  - open the agenda in a separate tab/window, or
  - screen-share it, or
  - paste the agenda link into chat.
Why “in-room agenda tool + automatic handover” isn’t fully implemented yet

- The PlugNMeet meeting room UI you use is served from the proxied upstream ( /meet/ → 10.248.29.198:8081 ) and its frontend code is not in this repo.
- Automatic handover requires one of:
  - PlugNMeet moderation APIs to promote/demote participants (chair → presenter → back), or
  - a plugNmeet plugin / custom panel inside the meeting client that can call those APIs.
What can be implemented next (realistic options)

- Option A (best UX): PlugNMeet custom panel
  - Add an “Agenda” side panel inside the PlugNMeet client (iframe to /hub/meetings/agenda.php?room_id=... ).
  - Add chair controls “Start item → promote assigned speaker”.
  - Requires modifying the PlugNMeet frontend deployment (upstream server), not just this repo.
- Option B (fast + works with PlugNMeet features): use PlugNMeet shared notes (Etherpad)
  - Auto-create a “shared notes” document per meeting and inject the agenda document into it.
  - Participants see it inside the room without leaving.
  - You still need a mapping to do automatic presenter handover (that part still requires moderation APIs).
- Vote sync with PlugNMeet polls
  - Needs explicit integration with PlugNMeet poll endpoints (create poll from vote, sync results back, close poll on vote close).
  - That again depends on PlugNMeet API availability and where credentials live.
## What was added to reduce agenda/series “extra steps” (already implemented in Hub)
- Agenda now shows:
  - Previous meetings in the same series (quick links to Agenda + Votes)
  - Next meeting suggestions (+7/+14/+21 days) with one-click links that open the Meetings scheduler prefilled
  - File: agenda.php
- Meetings scheduler now supports URL-prefill:
  - prefill_title , prefill_date , prefill_time , prefill_series_id
  - File: index.php
## Voting rule (class weighting) implemented
Per your rule:

- founders: 1 share = 1000 votes
- shareholders: 1 share = 1 vote
- mvi: 0 votes (ineligible)
Implemented:

- MVI users can’t cast ballots (“Not eligible to vote.”)
- File: vote.php
## Governance finalization (PDF) + reminders + translations: status
Still outstanding (runbook)

- Tamper-proof PDF minutes/protocol export integrated into the meeting folder and indexed.
  - /pdf-tools/index.php is a static UI proxy; there isn’t a simple server-side “POST html → PDF” API exposed in this repo yet. Next step is to locate the BentoPDF backend endpoint (or add a small server wrapper) and then add “Finalize → Generate PDF” in the agenda.
- Phone-based reminders via WhatsApp/Telegram without an API
  - Fully automated WhatsApp/Telegram delivery from the server requires an API/bot/gateway. Sending “from the user’s phone” cannot be done server-side without a client app/agent running on the user’s device.
  - Safe proposals:
    - Web push notifications (device-local, per-user, per-tenant).
    - Telegram bot per tenant (user opts in by chatting the bot; reminders go to that chat).
    - WhatsApp Cloud API per tenant (requires a business number; not “from user phone”).
- In-account reminder 2 hours before meeting
  - Partial implementation: Meetings page now polls /hub/meetings/reminders_api.php and shows a popup notice + short beep for meetings within the next mh_meeting_reminder_minutes (default 120).
  - Files:
    - reminders_api.php
    - index.php
  - Still pending: make this global across Hub pages + add a real user settings UI.
- Translations when subtitles are missing
  - Not implemented yet in meeting-artifacts.php (it transcribes/summarizes, but does not translate). This needs a confirmed translation service endpoint or model lane.
If you want, I can proceed next with:

1. making the 2-hour reminder popup global (Global UI widget + user settings), and
2. adding a “Finalize → PDF” button in the agenda by wiring a small server-side PDF render endpoint (either via the existing PDF stack or a new wrapper).
