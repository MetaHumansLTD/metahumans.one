<?php
require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/gear/meet/calendar_helpers.php';
require_once dirname(__DIR__, 2) . '/auth/tenant_provisioning.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$user = $_SESSION['mh_auth_user'] ?? '';
if (!is_string($user) || trim($user) === '') {
    header('Location: /auth/login.php?redirect=' . rawurlencode('/hub/calendar/'), true, 302);
    exit;
}
$user = trim($user);
mh_apply_tenant_context('user:' . $user);

if (!isset($_SESSION['mh_calendar_csrf']) || !is_string($_SESSION['mh_calendar_csrf']) || $_SESSION['mh_calendar_csrf'] === '') {
    $_SESSION['mh_calendar_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['mh_calendar_csrf'];

$templates = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__, 2) . '/templates');
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Humans Calendar</title>
    <?php if (is_file($templates . '/global-ui/includes/complete-head.php')) include_once $templates . '/global-ui/includes/complete-head.php'; ?>
    <style>
        :root {
            color-scheme: dark;
            --mh-bg: #020617;
            --mh-surface: #020617;
            --mh-surface-alt: #030712;
            --mh-border: #1f2937;
            --mh-accent: #38bdf8;
            --mh-accent-soft: rgba(56, 189, 248, 0.18);
            --mh-primary: #22c55e;
            --mh-text: #e5e7eb;
            --mh-muted: #9ca3af;
            --mh-grid-line: #111827;
            --mh-event-bg: rgba(56, 189, 248, 0.16);
            --mh-event-border: rgba(56, 189, 248, 0.8);
            --mh-today-bg: rgba(34, 197, 94, 0.15);
            --mh-today-border: rgba(34, 197, 94, 0.9);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background: radial-gradient(circle at top, #0b1220 0%, var(--mh-bg) 60%);
            color: var(--mh-text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            min-height: 100vh;
        }

        main.main-content {
            max-width: 1220px;
            margin: 0 auto;
            padding: 22px 18px 40px;
        }

        .calendar-shell {
            background: rgba(2, 6, 23, 0.7);
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            padding: 22px 22px 20px;
            box-shadow: 0 20px 60px rgba(2, 6, 23, 0.8);
        }

        .calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 18px;
        }

        .calendar-title-block {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .calendar-title {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .calendar-subtitle {
            font-size: 0.9rem;
            color: var(--mh-muted);
        }

        .calendar-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .calendar-button,
        .calendar-button-primary {
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.6);
            background: rgba(2, 6, 23, 0.55);
            color: var(--mh-text);
            padding: 8px 14px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: background 0.15s ease, border 0.15s ease, transform 0.15s ease;
            white-space: nowrap;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: 0 10px 28px rgba(2, 6, 23, 0.55);
        }

        .calendar-button:hover {
            border-color: rgba(148, 163, 184, 0.9);
            transform: translateY(-1px);
        }

        .calendar-button-primary {
            border-color: rgba(34, 197, 94, 0.6);
            background: rgba(34, 197, 94, 0.16);
            color: rgba(220, 252, 231, 0.95);
        }

        .calendar-button-primary:hover {
            border-color: rgba(34, 197, 94, 0.9);
        }

        .calendar-current-month {
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            padding: 8px 16px;
            min-width: 132px;
            text-align: center;
            font-weight: 700;
            color: rgba(226, 232, 240, 0.9);
            background: rgba(2, 6, 23, 0.6);
        }

        .calendar-grid {
            background: rgba(2, 6, 23, 0.7);
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.14);
            overflow: hidden;
        }

        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            background: rgba(2, 6, 23, 0.9);
            border-bottom: 1px solid var(--mh-grid-line);
        }

        .calendar-weekday {
            padding: 12px 12px 10px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            color: rgba(148, 163, 184, 0.92);
            text-transform: uppercase;
        }

        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            grid-auto-rows: minmax(108px, auto);
        }

        .calendar-day {
            border-right: 1px solid var(--mh-grid-line);
            border-bottom: 1px solid var(--mh-grid-line);
            padding: 10px;
            position: relative;
            background: rgba(2, 6, 23, 0.55);
            min-height: 108px;
        }

        .calendar-day:nth-child(7n) {
            border-right: none;
        }

        .calendar-day-date {
            font-size: 0.82rem;
            font-weight: 600;
            color: rgba(226, 232, 240, 0.85);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .calendar-day-outside {
            color: rgba(148, 163, 184, 0.5);
        }

        .calendar-day-today {
            outline: 1px solid var(--mh-today-border);
            outline-offset: -1px;
            background: rgba(34, 197, 94, 0.08);
        }

        .calendar-events {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .calendar-event {
            border-radius: 12px;
            border: 1px solid var(--mh-event-border);
            background: var(--mh-event-bg);
            padding: 7px 10px;
            font-size: 0.78rem;
            color: rgba(226, 232, 240, 0.92);
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: transform 0.12s ease, border 0.12s ease;
            line-height: 1.2;
        }

        .calendar-event:hover {
            transform: translateY(-1px);
            border-color: rgba(129, 230, 217, 0.85);
        }

        .calendar-event-time {
            font-weight: 700;
            color: rgba(226, 232, 240, 0.95);
            min-width: 56px;
            text-align: center;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            padding: 2px 8px;
            background: rgba(2, 6, 23, 0.55);
        }

        .calendar-footer {
            margin-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .calendar-legend {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(148, 163, 184, 0.9);
            font-size: 0.85rem;
        }

        .calendar-legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .calendar-legend-swatch {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--mh-event-bg);
            border: 1px solid var(--mh-event-border);
        }

        .calendar-timezone {
            font-size: 0.85rem;
            color: rgba(148, 163, 184, 0.9);
        }

        .calendar-event-dialog-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.75);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 40;
            padding: 16px;
        }

        .calendar-event-dialog {
            background: linear-gradient(to bottom right, #020617, #020617);
            border-radius: 16px;
            border: 1px solid rgba(148, 163, 184, 0.6);
            padding: 18px 18px 14px;
            max-width: 420px;
            width: 100%;
            box-shadow:
                0 20px 60px rgba(15, 23, 42, 0.95),
                0 0 0 1px rgba(15, 23, 42, 0.7);
        }

        .calendar-event-dialog-title {
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .calendar-event-dialog-meta {
            font-size: 0.8rem;
            color: var(--mh-muted);
            margin-bottom: 10px;
        }

        .calendar-event-dialog-links {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 10px;
        }

        .calendar-event-dialog-link {
            font-size: 0.8rem;
            color: #a5b4fc;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .calendar-event-dialog-link:hover {
            text-decoration: underline;
        }

        .calendar-edit {
            margin-top: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .calendar-input {
            width: 100%;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(2, 6, 23, 0.55);
            color: rgba(226, 232, 240, 0.92);
            padding: 10px 12px;
            font-size: 0.85rem;
        }

        .calendar-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .calendar-event-dialog-close {
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.45);
            background: rgba(15, 23, 42, 0.9);
            color: var(--mh-text);
            padding: 8px 14px;
            font-size: 0.82rem;
            cursor: pointer;
            font-weight: 700;
        }

        .calendar-save {
            border-radius: 999px;
            border: 1px solid rgba(34, 197, 94, 0.7);
            background: rgba(34, 197, 94, 0.16);
            color: rgba(220, 252, 231, 0.95);
            padding: 8px 14px;
            font-size: 0.82rem;
            cursor: pointer;
            font-weight: 800;
        }

        .calendar-delete {
            border-radius: 999px;
            border: 1px solid rgba(239, 68, 68, 0.55);
            background: rgba(239, 68, 68, 0.12);
            color: rgba(254, 202, 202, 0.95);
            padding: 8px 14px;
            font-size: 0.82rem;
            cursor: pointer;
            font-weight: 800;
        }

        .calendar-error {
            margin-top: 10px;
            color: rgba(254, 202, 202, 0.95);
            font-size: 0.82rem;
            display: none;
        }

        @media (max-width: 768px) {
            main.main-content {
                padding: 14px 12px 28px;
            }

            .calendar-shell {
                padding: 16px 12px 18px;
            }

            .calendar-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .calendar-controls {
                width: 100%;
                justify-content: space-between;
            }

            .calendar-day {
                min-height: 84px;
            }
        }
    </style>
</head>
<body class="hub-calendar">
<?php if (is_file($templates . '/global-ui/includes/complete-body-start.php')) include_once $templates . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="calendar-shell">
        <div class="calendar-header">
            <div class="calendar-title-block">
                <div class="calendar-title">Meta Humans Calendar</div>
                <div class="calendar-subtitle">
                    Meetings scheduled by your Meta Human personas are shown in your local time.
                </div>
            </div>
            <div class="calendar-controls">
                <button id="btn-today" class="calendar-button">Today</button>
                <button id="btn-prev" class="calendar-button" aria-label="Previous month">&#x276E;</button>
                <button id="btn-next" class="calendar-button" aria-label="Next month">&#x276F;</button>
                <div id="current-month-label" class="calendar-current-month"></div>
                <button id="btn-schedule" class="calendar-button-primary">Schedule meeting</button>
                <button id="btn-refresh" class="calendar-button-primary">
                    Refresh meetings
                </button>
            </div>
        </div>

        <div class="calendar-grid">
            <div class="calendar-weekdays" id="calendar-weekdays"></div>
            <div class="calendar-days" id="calendar-days"></div>
        </div>

        <div class="calendar-footer">
            <div class="calendar-legend">
                <div class="calendar-legend-item">
                    <span class="calendar-legend-swatch"></span>
                    <span>Scheduled Meta Humans meetings</span>
                </div>
            </div>
            <div class="calendar-timezone" id="calendar-timezone"></div>
        </div>
    </div>

    <div class="calendar-event-dialog-backdrop" id="event-dialog-backdrop">
        <div class="calendar-event-dialog">
            <div class="calendar-event-dialog-title" id="event-dialog-title"></div>
            <div class="calendar-event-dialog-meta" id="event-dialog-meta"></div>
            <div class="calendar-event-dialog-links" id="event-dialog-links"></div>
            <div class="calendar-edit">
                <input class="calendar-input" id="event-edit-title" placeholder="Title">
                <input class="calendar-input" id="event-edit-when" type="datetime-local">
            </div>
            <div class="calendar-error" id="event-dialog-error"></div>
            <div class="calendar-actions">
                <button id="event-dialog-delete" class="calendar-delete">Delete</button>
                <button id="event-dialog-save" class="calendar-save">Save</button>
                <button id="event-dialog-close" class="calendar-event-dialog-close">Close</button>
            </div>
        </div>
    </div>

    <div class="calendar-event-dialog-backdrop" id="schedule-dialog-backdrop">
        <div class="calendar-event-dialog">
            <div class="calendar-event-dialog-title">Schedule meeting</div>
            <div class="calendar-event-dialog-meta">Create a meeting and generate invite links.</div>
            <div class="calendar-edit">
                <input class="calendar-input" id="sched-title" placeholder="Meeting name (e.g. Dev Sync)">
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <input class="calendar-input" id="sched-date" type="date" style="flex:1; min-width:160px;">
                    <input class="calendar-input" id="sched-time" type="time" style="flex:1; min-width:140px;">
                </div>
            </div>
            <div class="calendar-error" id="schedule-dialog-error"></div>
            <div class="calendar-actions">
                <button id="schedule-dialog-create" class="calendar-save">Create</button>
                <button id="schedule-dialog-close" class="calendar-event-dialog-close">Close</button>
            </div>
        </div>
    </div>

    <div class="calendar-event-dialog-backdrop" id="share-frame-backdrop">
        <div class="calendar-event-dialog" style="max-width:520px;padding:0;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 12px 10px;border-bottom:1px solid rgba(148,163,184,.22);">
                <div style="font-weight:800;">Invite participants</div>
                <button id="share-frame-close" class="calendar-event-dialog-close">Close</button>
            </div>
            <iframe id="share-frame" title="Meeting share" src="about:blank" style="width:100%;height:560px;border:0;background:transparent;"></iframe>
        </div>
    </div>
</main>
<?php if (is_file($templates . '/global-ui/includes/complete-body-end.php')) include_once $templates . '/global-ui/includes/complete-body-end.php'; ?>
<script>
    const MH_CAL_CSRF = <?php echo json_encode($csrf); ?>;
    const weekdaysShort = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

    const weekdaysContainer = document.getElementById("calendar-weekdays");
    const daysContainer = document.getElementById("calendar-days");
    const monthLabel = document.getElementById("current-month-label");
    const timezoneLabel = document.getElementById("calendar-timezone");

    const btnToday = document.getElementById("btn-today");
    const btnPrev = document.getElementById("btn-prev");
    const btnNext = document.getElementById("btn-next");
    const btnSchedule = document.getElementById("btn-schedule");
    const btnRefresh = document.getElementById("btn-refresh");

    const dialogBackdrop = document.getElementById("event-dialog-backdrop");
    const dialogTitle = document.getElementById("event-dialog-title");
    const dialogMeta = document.getElementById("event-dialog-meta");
    const dialogLinks = document.getElementById("event-dialog-links");
    const dialogClose = document.getElementById("event-dialog-close");
    const dialogSave = document.getElementById("event-dialog-save");
    const dialogDelete = document.getElementById("event-dialog-delete");
    const dialogError = document.getElementById("event-dialog-error");
    const editTitle = document.getElementById("event-edit-title");
    const editWhen = document.getElementById("event-edit-when");

    const scheduleBackdrop = document.getElementById("schedule-dialog-backdrop");
    const scheduleClose = document.getElementById("schedule-dialog-close");
    const scheduleCreate = document.getElementById("schedule-dialog-create");
    const scheduleError = document.getElementById("schedule-dialog-error");
    const schedTitle = document.getElementById("sched-title");
    const schedDate = document.getElementById("sched-date");
    const schedTime = document.getElementById("sched-time");
    const shareFrameBackdrop = document.getElementById("share-frame-backdrop");
    const shareFrameClose = document.getElementById("share-frame-close");
    const shareFrame = document.getElementById("share-frame");


    let currentDate = new Date();
    let events = [];
    let activeEvent = null;

    function renderWeekdays() {
        weekdaysContainer.innerHTML = "";
        for (const name of weekdaysShort) {
            const div = document.createElement("div");
            div.className = "calendar-weekday";
            div.textContent = name;
            weekdaysContainer.appendChild(div);
        }
    }

    function renderMonth() {
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || "Local time";
        const monthFormatter = new Intl.DateTimeFormat(undefined, { month: "long", year: "numeric" });
        monthLabel.textContent = monthFormatter.format(currentDate);
        timezoneLabel.textContent = "Times shown in: " + tz;

        daysContainer.innerHTML = "";

        const monthStart = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
        const monthEnd = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 0);
        const startDay = monthStart.getDay();
        const totalDays = monthEnd.getDate();

        const prevMonthDays = startDay;
        const totalCells = Math.ceil((prevMonthDays + totalDays) / 7) * 7;

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const allEventsByDay = new Map();
        for (const ev of events) {
            if (!ev.start) continue;
            const d = new Date(ev.start);
            const key = d.getFullYear() + "-" + (d.getMonth() + 1) + "-" + d.getDate();
            if (!allEventsByDay.has(key)) {
                allEventsByDay.set(key, []);
            }
            allEventsByDay.get(key).push(ev);
        }

        for (let cellIndex = 0; cellIndex < totalCells; cellIndex++) {
            const cell = document.createElement("div");
            cell.className = "calendar-day";

            const dayOffset = cellIndex - prevMonthDays + 1;
            const cellDate = new Date(currentDate.getFullYear(), currentDate.getMonth(), dayOffset);

            const label = document.createElement("div");
            label.className = "calendar-day-date";
            label.textContent = String(cellDate.getDate());

            if (cellDate.getMonth() !== currentDate.getMonth()) {
                label.classList.add("calendar-day-outside");
            }

            const cellDateMidnight = new Date(cellDate);
            cellDateMidnight.setHours(0, 0, 0, 0);
            if (cellDateMidnight.getTime() === today.getTime()) {
                cell.classList.add("calendar-day-today");
            }

            cell.appendChild(label);

            const eventsContainer = document.createElement("div");
            eventsContainer.className = "calendar-events";

            const key = cellDate.getFullYear() + "-" + (cellDate.getMonth() + 1) + "-" + cellDate.getDate();
            const dayEvents = allEventsByDay.get(key) || [];

            if (dayEvents.length === 0) {
                cell.appendChild(eventsContainer);
                daysContainer.appendChild(cell);
                continue;
            }

            dayEvents.sort((a, b) => {
                if (!a.start || !b.start) return 0;
                return new Date(a.start) - new Date(b.start);
            });

            for (const ev of dayEvents) {
                const evDiv = document.createElement("div");
                evDiv.className = "calendar-event";
                const spanTime = document.createElement("span");
                spanTime.className = "calendar-event-time";
                if (ev.start) {
                    const d = new Date(ev.start);
                    spanTime.textContent = d.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });
                } else {
                    spanTime.textContent = "All day";
                }
                const spanTitle = document.createElement("span");
                spanTitle.textContent = ev.title || "Meeting";
                evDiv.appendChild(spanTime);
                evDiv.appendChild(spanTitle);

                evDiv.addEventListener("click", () => openEventDialog(ev));

                eventsContainer.appendChild(evDiv);
            }

            cell.appendChild(eventsContainer);
            daysContainer.appendChild(cell);
        }
    }

    function mapApiEvent(e) {
        let start = null;
        if (e.start_utc) {
            start = e.start_utc;
        } else if (e.scheduled_for_text) {
            start = e.scheduled_for_text;
        }
        return {
            id: e.id,
            roomId: e.room_id || "",
            title: e.title || "MetaHumans Meeting",
            start,
            inviteUrl: e.invite_url || null,
            presenterJoinUrl: e.presenter_join_url || null,
            participantJoinUrl: e.participant_join_url || null,
            scheduledText: e.scheduled_for_text || null
        };
    }

    async function loadEvents() {
        btnRefresh.disabled = true;
        btnRefresh.textContent = "Refreshing…";
        try {
            const res = await fetch("/hub/calendar/events.php?ts=" + Date.now(), { credentials: "include" });
            if (!res.ok) {
                throw new Error("API error: " + res.status);
            }
            const ct = (res.headers.get("content-type") || "").toLowerCase();
            const raw = await res.text();
            if (!ct.includes("application/json")) {
                throw new Error("Non-JSON response (" + ct + "): " + raw.slice(0, 120));
            }
            const data = JSON.parse(raw);
            if (data && Array.isArray(data.events)) {
                events = data.events.map(mapApiEvent);
            } else {
                events = [];
            }
            console.log("Calendar events loaded:", events.length);
        } catch (err) {
            console.error("Failed to load calendar events:", err);
            events = [];
        } finally {
            btnRefresh.disabled = false;
            btnRefresh.textContent = "Refresh meetings";
            renderMonth();
        }
    }

    function setDialogError(msg) {
        const m = (msg || "").trim();
        if (!m) {
            dialogError.style.display = "none";
            dialogError.textContent = "";
            return;
        }
        dialogError.style.display = "block";
        dialogError.textContent = m;
    }

    function setScheduleError(msg) {
        const m = (msg || "").trim();
        if (!m) {
            scheduleError.style.display = "none";
            scheduleError.textContent = "";
            return;
        }
        scheduleError.style.display = "block";
        scheduleError.textContent = m;
    }


    function openScheduleDialog() {
        setScheduleError("");
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, "0");
        const d = String(now.getDate()).padStart(2, "0");
        const hh = String(now.getHours()).padStart(2, "0");
        const mm = String(now.getMinutes()).padStart(2, "0");
        if (schedDate && !schedDate.value) schedDate.value = `${y}-${m}-${d}`;
        if (schedTime && !schedTime.value) schedTime.value = `${hh}:${mm}`;
        scheduleBackdrop.style.display = "flex";
        try { schedTitle.focus(); } catch(e) {}
    }

    function closeScheduleDialog() {
        scheduleBackdrop.style.display = "none";
    }

    function openShareFrame(src) {
        try {
            shareFrame.src = src;
        } catch (e) {}
        shareFrameBackdrop.style.display = "flex";
    }

    function closeShareFrame() {
        shareFrameBackdrop.style.display = "none";
        try { shareFrame.src = "about:blank"; } catch (e) {}
    }

    function mhCopyValue(id) {
        try {
            var el = document.getElementById(id);
            if (!el) return;
            el.focus();
            el.select();
            var v = el.value || "";
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(v);
            } else {
                document.execCommand("copy");
            }
        } catch (e) {}
    }
    window.mhCopyValue = mhCopyValue;

    function mhCopyInviteBundle() {
        try {
            var pv = invitePresenter.value || "";
            var gv = inviteParticipant.value || "";
            var t = "Presenter link:\n" + pv + "\n\nParticipant link:\n" + gv;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(t);
            }
        } catch (e) {}
    }
    window.mhCopyInviteBundle = mhCopyInviteBundle;

    function mhShareInvite() {
        try {
            var url = inviteParticipant.value || "";
            if (navigator.share) {
                navigator.share({ title: "Meeting invite", text: "Join my meeting", url: url });
            } else {
                mhCopyInviteBundle();
            }
        } catch (e) {
            mhCopyInviteBundle();
        }
    }
    window.mhShareInvite = mhShareInvite;

    function openEventDialog(ev) {
        activeEvent = ev;
        setDialogError("");
        dialogTitle.textContent = ev.title || "MetaHumans Meeting";

        let whenText = "";
        let localValue = "";
        if (ev.start) {
            const d = new Date(ev.start);
            whenText = d.toLocaleString(undefined, { dateStyle: "full", timeStyle: "short" });
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, "0");
            const dd = String(d.getDate()).padStart(2, "0");
            const hh = String(d.getHours()).padStart(2, "0");
            const mm = String(d.getMinutes()).padStart(2, "0");
            localValue = `${y}-${m}-${dd}T${hh}:${mm}`;
        } else if (ev.scheduledText) {
            whenText = ev.scheduledText;
        } else {
            whenText = "Scheduled time not available.";
        }
        dialogMeta.textContent = whenText;

        dialogLinks.innerHTML = "";
        if (ev.can_present && ev.presenterJoinUrl) {
            const a = document.createElement("a");
            a.href = ev.presenterJoinUrl;
            a.target = "_blank";
            a.rel = "noopener";
            a.className = "calendar-event-dialog-link";
            a.textContent = "Open presenter link";
            dialogLinks.appendChild(a);
        }
        if (ev.participantJoinUrl) {
            const a = document.createElement("a");
            a.href = ev.participantJoinUrl;
            a.target = "_blank";
            a.rel = "noopener";
            a.className = "calendar-event-dialog-link";
            a.textContent = "Open participant link";
            dialogLinks.appendChild(a);
        }

        editTitle.value = ev.title || "";
        editWhen.value = localValue;
        dialogBackdrop.style.display = "flex";
    }

    function closeEventDialog() {
        dialogBackdrop.style.display = "none";
        activeEvent = null;
    }

    function ymdHisFromDate(d) {
        const y = d.getUTCFullYear();
        const m = String(d.getUTCMonth() + 1).padStart(2, "0");
        const dd = String(d.getUTCDate()).padStart(2, "0");
        const hh = String(d.getUTCHours()).padStart(2, "0");
        const mm = String(d.getUTCMinutes()).padStart(2, "0");
        const ss = String(d.getUTCSeconds()).padStart(2, "0");
        return `${y}-${m}-${dd} ${hh}:${mm}:${ss}`;
    }

    async function postCalendar(action) {
        if (!activeEvent || !activeEvent.id) return;
        setDialogError("");

        const fd = new FormData();
        fd.append("csrf", MH_CAL_CSRF);
        fd.append("action", action);
        fd.append("id", String(activeEvent.id));
        if (action === "update") {
            const t = (editTitle.value || "").trim();
            fd.append("title", t);
            const lv = (editWhen.value || "").trim();
            if (lv) {
                const d = new Date(lv);
                if (!isNaN(d.getTime())) {
                    fd.append("scheduled_utc", ymdHisFromDate(d));
                }
            } else {
                fd.append("scheduled_utc", "");
            }
        }

        try {
            const res = await fetch("/hub/calendar/api.php", { method: "POST", body: fd, credentials: "include" });
            const data = await res.json().catch(() => null);
            if (!res.ok || !data || data.ok !== true) {
                const msg = data && data.error ? data.error : ("Request failed (" + res.status + ")");
                setDialogError(msg);
                return;
            }
            closeEventDialog();
            await loadEvents();
        } catch (e) {
            setDialogError("Request failed");
        }
    }

    async function createMeetingFromSchedule() {
        setScheduleError("");
        const title = (schedTitle.value || "").trim();
        const date = (schedDate.value || "").trim();
        const time = (schedTime.value || "").trim();
        if (!title) {
            setScheduleError("Meeting name is required.");
            return;
        }
        const fd = new FormData();
        fd.append("title", title);
        fd.append("date", date);
        fd.append("time", time);
        try {
            scheduleCreate.disabled = true;
            scheduleCreate.textContent = "Creating…";
            const res = await fetch("/hub/meet/schedule-api.php", { method: "POST", body: fd, credentials: "include" });
            const ct = (res.headers.get("content-type") || "").toLowerCase();
            const raw = await res.text();
            if (!ct.includes("application/json")) {
                throw new Error(raw.slice(0, 120));
            }
            const data = JSON.parse(raw);
            if (!res.ok || !data || data.ok !== true) {
                let msg = data && data.error ? data.error : ("Request failed (" + res.status + ")");
                if (data && data.error === "insufficient_tokens") {
                    msg = "Insufficient tokens. Balance: " + String(data.balance || data.balance_tokens || 0) + " · Cost: " + String(data.cost || data.cost_tokens || 0);
                }
                setScheduleError(msg);
                return;
            }
            closeScheduleDialog();
            const src = "/hub/meet/meeting_share.php?room_id=" + encodeURIComponent(data.room_id || "") + "&embed=1" + (data.scheduled_text ? ("&date=" + encodeURIComponent((data.scheduled_text.split(/\s+/)[0] || "")) + "&time=" + encodeURIComponent((data.scheduled_text.split(/\s+/)[1] || ""))) : "");
            openShareFrame(src);
            await loadEvents();
        } catch (e) {
            setScheduleError("Request failed");
        } finally {
            scheduleCreate.disabled = false;
            scheduleCreate.textContent = "Create";
        }
    }

    dialogClose.addEventListener("click", closeEventDialog);
    dialogBackdrop.addEventListener("click", (e) => {
        if (e.target === dialogBackdrop) {
            closeEventDialog();
        }
    });
    dialogSave.addEventListener("click", () => postCalendar("update"));
    dialogDelete.addEventListener("click", () => postCalendar("delete"));

    btnSchedule.addEventListener("click", openScheduleDialog);
    scheduleClose.addEventListener("click", closeScheduleDialog);
    scheduleBackdrop.addEventListener("click", (e) => {
        if (e.target === scheduleBackdrop) closeScheduleDialog();
    });
    scheduleCreate.addEventListener("click", createMeetingFromSchedule);

    shareFrameClose.addEventListener("click", closeShareFrame);
    shareFrameBackdrop.addEventListener("click", (e) => {
        if (e.target === shareFrameBackdrop) closeShareFrame();
    });
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && shareFrameBackdrop.style.display === "flex") {
            e.preventDefault();
            closeShareFrame();
        }
    });

    btnToday.addEventListener("click", () => {
        currentDate = new Date();
        renderMonth();
    });

    btnPrev.addEventListener("click", () => {
        currentDate = new Date(currentDate.getFullYear(), currentDate.getMonth() - 1, 1);
        renderMonth();
    });

    btnNext.addEventListener("click", () => {
        currentDate = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 1);
        renderMonth();
    });

    btnRefresh.addEventListener("click", () => {
        loadEvents();
    });

    renderWeekdays();
    renderMonth();
    loadEvents();
</script>
</body>
</html>
