<?php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
require_once __DIR__ . '/calendar_helpers.php';
require_once dirname(dirname(__DIR__)) . '/auth/kripz_gate.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
if ($currentUser === '') {
    header('Location: /auth/login.php?redirect=' . rawurlencode('/hub/calendar/'), true, 302);
    exit;
}
if (function_exists('mh_kripz_is_role') && !mh_kripz_is_role()) {
    header('Location: /hub/calendar/', true, 302);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Humans Meeting Calendar</title>
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
            padding: 24px;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #020617 0, #020617 40%, #020617 100%);
            color: var(--mh-text);
            min-height: 100vh;
        }

        .calendar-shell {
            max-width: 1200px;
            margin: 0 auto;
            background: linear-gradient(to bottom right, rgba(15, 23, 42, 0.95), rgba(15, 23, 42, 0.9));
            border-radius: 20px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            box-shadow:
                0 20px 60px rgba(15, 23, 42, 0.9),
                0 0 0 1px rgba(15, 23, 42, 0.7);
            padding: 20px 22px 24px;
        }

        .calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .calendar-title-block {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .calendar-title {
            font-size: 1.2rem;
            font-weight: 600;
            letter-spacing: 0.03em;
        }

        .calendar-subtitle {
            font-size: 0.8rem;
            color: var(--mh-muted);
        }

        .calendar-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .calendar-button {
            border-radius: 999px;
            border: 1px solid var(--mh-border);
            background: rgba(15, 23, 42, 0.9);
            color: var(--mh-text);
            padding: 6px 12px;
            font-size: 0.8rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .calendar-button:hover {
            border-color: rgba(148, 163, 184, 0.7);
            background: rgba(15, 23, 42, 1);
        }

        .calendar-button-primary {
            border-color: rgba(34, 197, 94, 0.9);
            background: radial-gradient(circle at top left, rgba(34, 197, 94, 0.18), rgba(21, 128, 61, 0.65));
            color: #ecfdf3;
        }

        .calendar-button-primary:hover {
            background: radial-gradient(circle at top, rgba(34, 197, 94, 0.28), rgba(22, 163, 74, 0.9));
        }

        .calendar-current-month {
            font-size: 0.95rem;
            font-weight: 500;
            padding: 4px 12px;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.4);
            background: linear-gradient(to right, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.9));
        }

        .calendar-grid {
            margin-top: 6px;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(31, 41, 55, 0.9);
            background: radial-gradient(circle at top, #020617 0, #020617 40%, #020617 100%);
        }

        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            border-bottom: 1px solid var(--mh-grid-line);
            background: linear-gradient(to bottom, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.7));
        }

        .calendar-weekday {
            padding: 8px 10px;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--mh-muted);
            letter-spacing: 0.08em;
        }

        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
        }

        .calendar-day {
            min-height: 96px;
            border-right: 1px solid var(--mh-grid-line);
            border-bottom: 1px solid var(--mh-grid-line);
            padding: 6px 6px 8px;
            position: relative;
            vertical-align: top;
            font-size: 0.75rem;
        }

        .calendar-day:nth-child(7n) {
            border-right: none;
        }

        .calendar-day-date {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 999px;
            font-size: 0.75rem;
        }

        .calendar-day-outside {
            color: rgba(75, 85, 99, 0.7);
        }

        .calendar-day-today .calendar-day-date {
            background: var(--mh-today-bg);
            border: 1px solid var(--mh-today-border);
            color: #bbf7d0;
        }

        .calendar-events {
            margin-top: 4px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .calendar-event {
            border-radius: 8px;
            padding: 4px 6px;
            background: var(--mh-event-bg);
            border: 1px solid var(--mh-event-border);
            font-size: 0.7rem;
            cursor: pointer;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .calendar-event:hover {
            background: rgba(56, 189, 248, 0.25);
        }

        .calendar-event-time {
            font-weight: 500;
            margin-right: 4px;
        }

        .calendar-footer {
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: var(--mh-muted);
        }

        .calendar-legend {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .calendar-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .calendar-legend-swatch {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: var(--mh-event-bg);
            border: 1px solid var(--mh-event-border);
        }

        .calendar-timezone {
            text-align: right;
        }

        .calendar-empty {
            font-size: 0.8rem;
            color: var(--mh-muted);
            padding: 8px 0;
        }

        .calendar-event-dialog-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.75);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 40;
        }

        .calendar-event-dialog {
            background: linear-gradient(to bottom right, #020617, #020617);
            border-radius: 16px;
            border: 1px solid rgba(148, 163, 184, 0.6);
            padding: 18px 18px 14px;
            max-width: 360px;
            width: 100%;
            box-shadow:
                0 20px 60px rgba(15, 23, 42, 0.95),
                0 0 0 1px rgba(15, 23, 42, 0.7);
        }

        .calendar-event-dialog-title {
            font-size: 0.95rem;
            font-weight: 600;
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

        .calendar-event-dialog-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 4px;
        }

        .calendar-event-dialog-close {
            border-radius: 999px;
            border: 1px solid var(--mh-border);
            background: rgba(15, 23, 42, 0.9);
            color: var(--mh-text);
            padding: 6px 12px;
            font-size: 0.8rem;
            cursor: pointer;
        }

        .calendar-event-dialog-close:hover {
            border-color: rgba(148, 163, 184, 0.7);
        }

        @media (max-width: 768px) {
            body {
                padding: 12px;
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
<body>
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
            <div class="calendar-event-dialog-actions">
                <button id="event-dialog-close" class="calendar-event-dialog-close">Close</button>
            </div>
        </div>
    </div>

    <script>
        const weekdaysShort = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

        const weekdaysContainer = document.getElementById("calendar-weekdays");
        const daysContainer = document.getElementById("calendar-days");
        const monthLabel = document.getElementById("current-month-label");
        const timezoneLabel = document.getElementById("calendar-timezone");

        const btnToday = document.getElementById("btn-today");
        const btnPrev = document.getElementById("btn-prev");
        const btnNext = document.getElementById("btn-next");
        const btnRefresh = document.getElementById("btn-refresh");

        const dialogBackdrop = document.getElementById("event-dialog-backdrop");
        const dialogTitle = document.getElementById("event-dialog-title");
        const dialogMeta = document.getElementById("event-dialog-meta");
        const dialogLinks = document.getElementById("event-dialog-links");
        const dialogClose = document.getElementById("event-dialog-close");

        let currentDate = new Date();
        let events = [];

        function renderWeekdays() {
            weekdaysContainer.innerHTML = "";
            for (const name of weekdaysShort) {
                const div = document.createElement("div");
                div.className = "calendar-weekday";
                div.textContent = name;
                weekdaysContainer.appendChild(div);
            }
        }

        function startOfMonth(date) {
            return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1));
        }

        function addMonths(date, months) {
            return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + months, 1));
        }

        function renderMonth() {
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || "Local time";
            const monthFormatter = new Intl.DateTimeFormat(undefined, {
                month: "long",
                year: "numeric"
            });
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
                const params = new URLSearchParams(window.location.search);
                const sessionId = params.get("session_id");
                let url = "api.php";
                if (sessionId) {
                    url += "?session_id=" + encodeURIComponent(sessionId);
                }
                const res = await fetch(url, {
                    credentials: "include",
                    headers: {
                        "X-Requested-With": "XMLHttpRequest"
                    }
                });
                if (!res.ok) {
                    throw new Error("API error: " + res.status);
                }
                const data = await res.json();
                if (data && Array.isArray(data.events)) {
                    events = data.events.map(mapApiEvent);
                } else {
                    events = [];
                }
            } catch (err) {
                console.error("Failed to load calendar events:", err);
                events = [];
            } finally {
                btnRefresh.disabled = false;
                btnRefresh.textContent = "Refresh meetings";
                renderMonth();
            }
        }

        function openEventDialog(ev) {
            dialogTitle.textContent = ev.title || "MetaHumans Meeting";

            let whenText = "";
            if (ev.start) {
                const d = new Date(ev.start);
                whenText = d.toLocaleString(undefined, {
                    dateStyle: "full",
                    timeStyle: "short"
                });
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
            if (ev.inviteUrl && ev.inviteUrl !== ev.participantJoinUrl) {
                const a = document.createElement("a");
                a.href = ev.inviteUrl;
                a.target = "_blank";
                a.rel = "noopener";
                a.className = "calendar-event-dialog-link";
                a.textContent = "Open share page";
                dialogLinks.appendChild(a);
            }

            dialogBackdrop.style.display = "flex";
        }

        function closeEventDialog() {
            dialogBackdrop.style.display = "none";
        }

        dialogClose.addEventListener("click", closeEventDialog);
        dialogBackdrop.addEventListener("click", (e) => {
            if (e.target === dialogBackdrop) {
                closeEventDialog();
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
