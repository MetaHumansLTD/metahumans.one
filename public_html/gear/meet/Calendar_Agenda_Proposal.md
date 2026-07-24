# Calendar & Agenda Management System: Proposal

**Date:** February 2026
**Subject:** Integrated Meeting & Agenda Management Solution

---

## 1. Executive Summary
To complement the video conferencing infrastructure, we propose a dual-module system for **Meeting Scheduling** and **Agenda Governance**. This system ensures that every shareholder meeting is not just a video call, but a tracked, actionable governance event.

---

## 2. Module A: The "Google Calendar" Clone (Scheduling)
**Objective:** Provide a seamless booking interface where shareholders can schedule meetings, invite participants, and receive automated links.

### **Recommended Solution: Cal.com (Self-Hosted)**
*   **Why Cal.com:** It is the industry standard open-source alternative to Calendly/Google Calendar. It integrates natively with Meet (automatically generating video links) and allows for "Round Robin" or "Collective" scheduling.
*   **Key Features:**
    *   **Booking Page:** Each shareholder or board member gets a `company.com/username` link.
    *   **Auto-:** When a meeting is booked, a unique room (e.g., `meet.company.com/ShareholderVote-Feb14`) is instantly created and emailed to all attendees.
    *   **Time Zone Sync:** Auto-detects shareholder time zones (NY, London, Tokyo).
    *   **White-Label:** Fully brandable with company logo and colors.

---

## 3. Module B: Agenda & Minute Governance
**Objective:** A structured system to create agendas, track item status (In Process, Resolved, Approved), and carry over unfinished business to the next meeting automatically.

### **Recommended Solution: 4Minitz (Self-Hosted)**
*   **Why 4Minitz:** It is built specifically for "Meeting Series." It remembers the context of previous meetings.
*   **Workflow:**
    1.  **Pre-Meeting:** Secretary creates an agenda. Items from the *last* meeting marked "Open" are automatically imported to the top of the new agenda.
    2.  **During Meeting:**
        *   Take live minutes attached to each agenda item.
        *   Mark items as: `Info`, `Action`, `Decision` (Vote), or `Todo`.
    3.  **Post-Meeting:**
        *   Click "Finalize" to generate a tamper-proof PDF protocol.
        *   Email the PDF to all shareholders automatically.
    4.  **Next Meeting:** The cycle repeats; unresolved "Todos" auto-appear.

---

## 4. Integration Architecture (The "One-Stop" Portal)
We will unify these tools into a single **Shareholder Dashboard**:

1.  **Tab 1: Schedule:** Embeds the **Cal.com** interface. Users book time slots here.
2.  **Tab 2: Join Meeting:** Shows upcoming meetings. Clicking "Join" opens the **Meet** iframe.
3.  **Tab 3: Agendas:** Embeds **4Minitz**. Users can view past minutes or suggest items for the next agenda.
4.  **Tab 4: Voting:** Links to the **Helios/Snapshot** system (as defined in the Tokenomics paper).

---

## 5. Implementation Roadmap

*   **Week 1:** Deploy **Cal.com** (Docker container) and connect SMTP (Email) service.
*   **Week 2:** Deploy **4Minitz** (Meteor/Node.js) and configure "Shareholder" user roles.
*   **Week 3:** Build the "Dashboard Wrapper" (React/Next.js) to display all tools in one menu.
*   **Week 4:** Training session for Board Secretary on the "Agenda Carry-Over" workflow.

Current structure:
Detailed File Comparison Below is a functional comparison of the requested PHP files—their roles, inputs/outputs, side effects, and how they fit the “create → share → join → calendar → agendas → record → transcribe → translate → store → reschedule → re‑invite” concept.

- gear/studio/meeting.php
  
  - Path: meeting.php
  - Purpose: JSON API for meeting operations using secure plugnmeet.json + encrypted keys.
  - Inputs: JSON payload (likely create/start/stop room or generate a token).
  - Outputs: JSON; headers are no‑cache.
  - Dependencies: CUE security + paths; decrypts API key/secret from /home/onemeta/.data/config/plugnmeet.json via encryption key.
  - Notes: Safer credential handling than hardcoding; use this for programmatic room creation from the headless IDE.
- gear/studio/meeting_join.php
  
  - Path: meeting_join.php
  - Purpose: Token/redirect broker for joining a meeting via GPU server base (CUE_GPU_SERVER).
  - Inputs: POST (CORS open); possibly room info in body.
  - Outputs: JSON (AJAX) or sets headers; uses HMAC signature with constants.
  - Concern: Hardcoded PNM_API_KEY/SECRET values exist; should be replaced with encrypted storage for parity with meeting.php.
- gear/studio/meeting_share.php
  
  - Path: meeting_share.php
  - Purpose: HTML share page generating presenter/participant links and QR code for a given room_id.
  - Inputs: GET room_id, date, time.
  - Outputs: Branded HTML with share URLs pointing at /meet.php.
- studio/transcribe.php
  
  - Path: transcribe.php
  - Purpose: Upload endpoint for audio/video; base64 encodes and forwards to a transcription backend.
  - Inputs: POST multipart file, optional language.
  - Outputs: JSON with status/results; writes to tmp; deletes on failure.
  - Integration: Should be called post-recording to generate transcripts; can be wired to agenda generation.
- studio/meeting.php
  
  - Path: meeting.php
  - Purpose: JSON meeting API similar to gear/studio/meeting.php but with stricter config existence checks.
  - Inputs/Outputs: Same pattern; returns JSON with no-cache headers.
  - Dependencies: Encrypted config from plugnmeet.json.
- studio/meeting_join.php
  
  - Path: meeting_join.php
  - Purpose: Minimal redirector to [ o bj ec tO bj ec t ] GP U S ​ ER V ER : PORT with a roomId; no token creation.
  - Inputs: GET id (meeting id).
  - Outputs: HTTP redirect or small AJAX JSON with redirect URL.
  - Concern: Not secure (no token); OK for dev/Codespaces; not for production.
- Root meet.php
  
  - Path: meet.php
  - Purpose: Redirect shim; forwards any query string to booking.php.
  - Role in concept: Ensures all meeting joins go through the booking/flow UI.
- Root booking.php
  
  - Path: booking.php
  - Purpose: Full booking interface; HTML rendering with templates and meet helpers.
  - Inputs: Session or query; renders page with forms/flows for booking.
  - Role: Primary UI for scheduling; should be the source of room creation and calendar linking.
- gear/calendar/index.php
  
  - Path: index.php
  - Purpose: Web calendar UI with dark theme; reads user context.
  - Inputs: user session.
  - Role: Displays scheduled items; should be the target of “create calendar entries” and rescheduling workflows.
- gear/auto-agenda
  
  - Files:
    - auto-agenda.php : Generates a new agenda from a prior agenda row, writes to DB, optional HTML form for selection.
    - agenda.php : Renders tabular agenda items.
  - Role: Adds Agenda creation and listing to the loop; integrate with transcripts and summary outputs.
Mapping to the Concept

- Create: Use gear/studio/meeting.php or studio/meeting.php as the server‑side JSON API to create a PlugNMeet room and persist metadata. booking.php should call this.
- Share: gear/studio/meeting_share.php provides a branded share/QR page; booking flow can generate these links and push to /gear/calendar.
- Join: Production join must generate a signed token (do not use the simple studio redirect). Use the secured meeting APIs (gear/studio/meeting.php) to obtain tokens that point at https://metahumans.one/plugnmeet with the correct access_token.
- Calendar: booking.php should save events and call into gear/calendar/index.php’s backing store; these are present and just need the action hook to write events for each attendee.
- Agendas: gear/auto-agenda can generate from templates or prior agendas; wire its creation to the “meeting created” and “rescheduled” hooks.
- Record: recording is operationally wired but the recorder automation fails on the join page. Enable auto-join or align selectors so the recorder can pass prejoin automatically.
- Transcribe/Translate: studio/transcribe.php already handles uploads; couple it to the “recording ended” path to push the MP4 (or audio) for transcription and then save the text to meeting artifacts (artifacts path/DB).
- Store: Use artifacts_settings and a stable path (block storage or S3-mounted) to persist MP4s, transcripts, and agendas.
- Reschedule: booking.php can update calendar entries; when rescheduled, auto‑agenda can duplicate/adjust agendas; reinvite via email/ICS.
- Invites & Reminders: booking layer should generate ICS invites per-user with reminders; gear/calendar can reflect acceptance and changes.
Headless IDE Bookings

- Requirement: “Meetings must be booked via the headless IDE in /workspace.”
- Approach:
  - Expose a CLI/HTTP endpoint that the headless IDE invokes:
    - POST to gear/studio/meeting.php to create a room (returns roomId, token for host).
    - POST to booking.php (or a /gear/calendar API) to create calendar entries for all invitees and to enqueue invites/reminders.
    - Generate share links via gear/studio/meeting_share.php for distribution.
  - I can add a small internal endpoint (JSON only, no UI) that orchestrates: create room → create calendar → send invites → return all links/tokens.
Next Actions I Recommend

- Auto-join for recorder:
  - Update the client config assets/config.js to set a prejoin auto-join flag (if supported by your build), or append a join URL parameter the recorder already respects. I’ll implement the param/update so the recorder passes pre-join and records reliably.
- Harden the meeting_join endpoints:
  - Replace hardcoded PNM_API_KEY/SECRET in gear/studio/meeting_join.php with the encrypted config approach used in meeting.php.
- Confirm domain ports UI entry for superbrains.one:
  - I prepared the exact block to add; if you want me to apply it in place, I’ll patch ports.php to include the ‘superbrains.one’ array and (optionally) default it into the domain list.