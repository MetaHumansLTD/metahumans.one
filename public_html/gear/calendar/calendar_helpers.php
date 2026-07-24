<?php
declare(strict_types=1);

if (!function_exists('getContextAwareDatabase')) {
    require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
    $settingsPath = dirname(__DIR__) . '/settings/functions/database-context.php';
    if (file_exists($settingsPath)) {
        require_once $settingsPath;
    }
}

function calendar_get_db(): ?PDO
{
    if (function_exists('database_getContextAwareConnection')) {
        $pdo = database_getContextAwareConnection();
        return $pdo instanceof PDO ? $pdo : null;
    }
    if (function_exists('getContextAwareDatabase')) {
        $fn = 'getContextAwareDatabase';
        $pdo = $fn();
        return $pdo instanceof PDO ? $pdo : null;
    }
    return null;
}

function calendar_ensure_tables(PDO $db): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS mh_meetings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id VARCHAR(191) NOT NULL,
            title VARCHAR(255) NOT NULL,
            invite_url TEXT,
            presenter_join_url TEXT,
            participant_join_url TEXT,
            scheduled_for_utc DATETIME NULL,
            scheduled_for_text VARCHAR(255) NULL,
            created_at_utc DATETIME NOT NULL,
            created_by_user VARCHAR(191) NULL,
            series_id INT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'scheduled',
            canceled_at_utc DATETIME NULL,
            canceled_reason VARCHAR(255) NULL,
            session_id VARCHAR(191) NULL,
            persona_mode VARCHAR(64) NULL,
            tock_notified TINYINT(1) NOT NULL DEFAULT 0,
            token_charge_status VARCHAR(32) NOT NULL DEFAULT 'none',
            token_charge_amount INT NOT NULL DEFAULT 0,
            token_charge_due_utc DATETIME NULL,
            token_charged_at_utc DATETIME NULL,
            token_charge_error TEXT NULL,
            INDEX idx_mh_meetings_user (created_by_user),
            INDEX idx_mh_meetings_session (session_id),
            INDEX idx_mh_meetings_time (scheduled_for_utc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $db->exec($sql);

    $fkAlters = [];
    $alters = [
        "ALTER TABLE mh_meetings ADD COLUMN token_charge_status VARCHAR(32) NOT NULL DEFAULT 'none'",
        "ALTER TABLE mh_meetings ADD COLUMN token_charge_amount INT NOT NULL DEFAULT 0",
        "ALTER TABLE mh_meetings ADD COLUMN token_charge_due_utc DATETIME NULL",
        "ALTER TABLE mh_meetings ADD COLUMN token_charged_at_utc DATETIME NULL",
        "ALTER TABLE mh_meetings ADD COLUMN token_charge_error TEXT NULL",
        "ALTER TABLE mh_meetings ADD COLUMN series_id INT NULL",
        "ALTER TABLE mh_meetings ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'scheduled'",
        "ALTER TABLE mh_meetings ADD COLUMN canceled_at_utc DATETIME NULL",
        "ALTER TABLE mh_meetings ADD COLUMN canceled_reason VARCHAR(255) NULL",
    ];
    foreach ($alters as $q) {
        try {
            $db->exec($q);
        } catch (Throwable) {
        }
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_meeting_series (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_by_user VARCHAR(191) NOT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mh_series_user (created_by_user)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_meeting_agendas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            series_id INT NULL,
            agenda_version INT NOT NULL DEFAULT 1,
            agenda_json LONGTEXT NOT NULL,
            minutes_md LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NULL,
            UNIQUE KEY uniq_mh_agenda_meeting (meeting_id),
            INDEX idx_mh_agenda_series (series_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $fkAlters['mh_meeting_agendas'] = "
        ALTER TABLE mh_meeting_agendas ADD CONSTRAINT fk_agenda_meeting
        FOREIGN KEY (meeting_id) REFERENCES mh_meetings(id) ON DELETE CASCADE";

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_meeting_agenda_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            template_json LONGTEXT NOT NULL,
            created_by_user VARCHAR(191) NOT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NULL,
            UNIQUE KEY uniq_mh_agenda_tpl_name (name),
            INDEX idx_mh_agenda_tpl_user (created_by_user)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $tplAlters = [
        "ALTER TABLE mh_meeting_agenda_templates ADD COLUMN updated_at_utc DATETIME NULL",
        "ALTER TABLE mh_meeting_agenda_templates ADD UNIQUE KEY uniq_mh_agenda_tpl_name (name)",
    ];
    foreach ($tplAlters as $q) {
        try {
            $db->exec($q);
        } catch (Throwable) {
        }
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_meeting_votes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            series_id INT NULL,
            title VARCHAR(255) NOT NULL,
            kind VARCHAR(32) NOT NULL DEFAULT 'poll',
            weight_basis VARCHAR(32) NOT NULL DEFAULT 'shares',
            options_json TEXT NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'open',
            equity_snapshot_json LONGTEXT NULL,
            results_json LONGTEXT NULL,
            export_path TEXT NULL,
            export_sha256 VARCHAR(64) NULL,
            created_by_user VARCHAR(191) NOT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at_utc DATETIME NULL,
            INDEX idx_mh_votes_meeting (meeting_id),
            INDEX idx_mh_votes_series (series_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $fkAlters['mh_meeting_votes'] = "
        ALTER TABLE mh_meeting_votes ADD CONSTRAINT fk_votes_meeting
        FOREIGN KEY (meeting_id) REFERENCES mh_meetings(id) ON DELETE CASCADE";

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_meeting_ballots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vote_id INT NOT NULL,
            username VARCHAR(191) NOT NULL,
            choice VARCHAR(255) NOT NULL,
            weight INT NOT NULL DEFAULT 1,
            weight_basis VARCHAR(32) NOT NULL DEFAULT 'shares',
            equity_snapshot_json LONGTEXT NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mh_vote_user (vote_id, username),
            INDEX idx_mh_ballots_vote (vote_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $fkAlters['mh_meeting_ballots'] = "
        ALTER TABLE mh_meeting_ballots ADD CONSTRAINT fk_ballots_vote
        FOREIGN KEY (vote_id) REFERENCES mh_meeting_votes(id) ON DELETE CASCADE";

    $voteAlters = [
        "ALTER TABLE mh_meeting_votes ADD COLUMN weight_basis VARCHAR(32) NOT NULL DEFAULT 'shares'",
        "ALTER TABLE mh_meeting_votes ADD COLUMN equity_snapshot_json LONGTEXT NULL",
        "ALTER TABLE mh_meeting_votes ADD COLUMN results_json LONGTEXT NULL",
        "ALTER TABLE mh_meeting_votes ADD COLUMN export_path TEXT NULL",
        "ALTER TABLE mh_meeting_votes ADD COLUMN export_sha256 VARCHAR(64) NULL",
    ];
    foreach ($voteAlters as $q) {
        try {
            $db->exec($q);
        } catch (Throwable) {
        }
    }

    $ballotAlters = [
        "ALTER TABLE mh_meeting_ballots ADD COLUMN weight_basis VARCHAR(32) NOT NULL DEFAULT 'shares'",
        "ALTER TABLE mh_meeting_ballots ADD COLUMN equity_snapshot_json LONGTEXT NULL",
        "ALTER TABLE mh_meeting_ballots ADD COLUMN ip_address VARCHAR(64) NULL",
        "ALTER TABLE mh_meeting_ballots ADD COLUMN user_agent VARCHAR(255) NULL",
    ];
    foreach ($ballotAlters as $q) {
        try {
            $db->exec($q);
        } catch (Throwable) {
        }
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_meeting_vote_audit (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            vote_id INT NOT NULL,
            meeting_id INT NOT NULL,
            action VARCHAR(32) NOT NULL,
            actor VARCHAR(191) NOT NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            payload_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mh_vote_audit_vote (vote_id),
            INDEX idx_mh_vote_audit_meeting (meeting_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $fkAlters['mh_meeting_vote_audit'] = "
        ALTER TABLE mh_meeting_vote_audit ADD CONSTRAINT fk_vote_audit_vote
        FOREIGN KEY (vote_id) REFERENCES mh_meeting_votes(id) ON DELETE CASCADE,
        ADD CONSTRAINT fk_vote_audit_meeting
        FOREIGN KEY (meeting_id) REFERENCES mh_meetings(id) ON DELETE CASCADE";

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_meeting_artifacts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            record_id VARCHAR(191) NULL,
            kind VARCHAR(32) NOT NULL,
            lang VARCHAR(32) NULL,
            local_path TEXT NULL,
            sha256 VARCHAR(64) NULL,
            bytes BIGINT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'ready',
            meta_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NULL,
            UNIQUE KEY uniq_mh_art_meet_record_kind_lang (meeting_id, record_id, kind, lang),
            INDEX idx_mh_art_meeting (meeting_id),
            INDEX idx_mh_art_kind (kind),
            INDEX idx_mh_art_record (record_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $fkAlters['mh_meeting_artifacts'] = "
        ALTER TABLE mh_meeting_artifacts ADD CONSTRAINT fk_artifacts_meeting
        FOREIGN KEY (meeting_id) REFERENCES mh_meetings(id) ON DELETE CASCADE";

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_meeting_summary_index (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            record_id VARCHAR(191) NULL,
            lang VARCHAR(32) NULL,
            summary_text LONGTEXT NOT NULL,
            summary_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NULL,
            UNIQUE KEY uniq_mh_sum_meet_record_lang (meeting_id, record_id, lang),
            FULLTEXT KEY ft_mh_sum_text (summary_text)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $fkAlters['mh_meeting_summary_index'] = "
        ALTER TABLE mh_meeting_summary_index ADD CONSTRAINT fk_summary_index_meeting
        FOREIGN KEY (meeting_id) REFERENCES mh_meetings(id) ON DELETE CASCADE";

    try {
        $db->exec("ALTER TABLE mh_meeting_artifacts ADD UNIQUE KEY uniq_mh_art_meet_record_kind_lang (meeting_id, record_id, kind, lang)");
    } catch (Throwable) {
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_meeting_attendees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            username VARCHAR(191) NOT NULL,
            role VARCHAR(32) NOT NULL DEFAULT 'participant',
            name_display VARCHAR(255) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(64) NULL,
            invited_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            joined_at_utc DATETIME NULL,
            last_notified_at_utc DATETIME NULL,
            INDEX idx_mh_attendees_meeting (meeting_id),
            INDEX idx_mh_attendees_user (username),
            UNIQUE KEY uniq_mh_meet_user (meeting_id, username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $fkAlters['mh_meeting_attendees'] = "
        ALTER TABLE mh_meeting_attendees ADD CONSTRAINT fk_attendees_meeting
        FOREIGN KEY (meeting_id) REFERENCES mh_meetings(id) ON DELETE CASCADE";

    foreach ($fkAlters as $fk) {
        try { $db->exec($fk); } catch (Throwable) {}
    }
}

function calendar_find_active_meeting_by_room(PDO $db, string $roomId): ?array
{
    $roomId = trim($roomId);
    if ($roomId === '') {
        return null;
    }

    calendar_ensure_tables($db);

    $stmt = $db->prepare("
        SELECT *
        FROM mh_meetings
        WHERE room_id = :room_id
          AND status <> 'canceled'
        ORDER BY COALESCE(scheduled_for_utc, created_at_utc) DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([':room_id' => $roomId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function calendar_store_meeting(array $data): int
{
    $db = calendar_get_db();
    if (!$db) {
        return 0;
    }

    calendar_ensure_tables($db);

    $roomId = isset($data['room_id']) ? (string)$data['room_id'] : '';
    $title = isset($data['title']) ? (string)$data['title'] : 'MetaHumans Meeting';
    if ($roomId === '') {
        return 0;
    }

    $inviteUrl = isset($data['invite_url']) ? (string)$data['invite_url'] : null;
    $presenterJoin = isset($data['presenter_join_url']) ? (string)$data['presenter_join_url'] : null;
    $participantJoin = isset($data['participant_join_url']) ? (string)$data['participant_join_url'] : null;
    $scheduledUtc = isset($data['scheduled_for_utc']) ? (string)$data['scheduled_for_utc'] : null;
    $scheduledText = isset($data['scheduled_for_text']) ? (string)$data['scheduled_for_text'] : null;
    $createdBy = isset($data['created_by_user']) ? (string)$data['created_by_user'] : null;
    $seriesId = isset($data['series_id']) ? (int)$data['series_id'] : null;
    if ($seriesId !== null && $seriesId < 1) {
        $seriesId = null;
    }
    $status = isset($data['status']) ? trim((string)$data['status']) : '';
    if ($status === '') {
        $status = 'scheduled';
    }
    $sessionId = isset($data['session_id']) ? (string)$data['session_id'] : null;
    $personaMode = isset($data['persona_mode']) ? (string)$data['persona_mode'] : null;

    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));

    $stmt = $db->prepare("
        INSERT INTO mh_meetings (
            room_id,
            title,
            invite_url,
            presenter_join_url,
            participant_join_url,
            scheduled_for_utc,
            scheduled_for_text,
            created_at_utc,
            created_by_user,
            series_id,
            status,
            session_id,
            persona_mode,
            tock_notified
        ) VALUES (
            :room_id,
            :title,
            :invite_url,
            :presenter_join_url,
            :participant_join_url,
            :scheduled_for_utc,
            :scheduled_for_text,
            :created_at_utc,
            :created_by_user,
            :series_id,
            :status,
            :session_id,
            :persona_mode,
            0
        )
    ");

    $stmt->execute([
        ':room_id' => $roomId,
        ':title' => $title,
        ':invite_url' => $inviteUrl,
        ':presenter_join_url' => $presenterJoin,
        ':participant_join_url' => $participantJoin,
        ':scheduled_for_utc' => $scheduledUtc,
        ':scheduled_for_text' => $scheduledText,
        ':created_at_utc' => $nowUtc->format('Y-m-d H:i:s'),
        ':created_by_user' => $createdBy,
        ':series_id' => $seriesId,
        ':status' => $status,
        ':session_id' => $sessionId,
        ':persona_mode' => $personaMode,
    ]);

    $meetingId = (int)$db->lastInsertId();
    if ($meetingId > 0) {
        calendar_notify_tock($db, $meetingId);
    }
    return $meetingId;
}

function calendar_set_meeting_token_charge_pending(PDO $db, int $meetingId, int $amount, string $dueUtc): void
{
    $amount = max(0, $amount);
    if ($meetingId < 1 || $amount < 1 || $dueUtc === '') {
        return;
    }
    $stmt = $db->prepare("
        UPDATE mh_meetings
        SET token_charge_status = 'pending',
            token_charge_amount = :amt,
            token_charge_due_utc = :due,
            token_charged_at_utc = NULL,
            token_charge_error = NULL
        WHERE id = :id
    ");
    $stmt->execute([
        ':amt' => $amount,
        ':due' => $dueUtc,
        ':id' => $meetingId,
    ]);
}

function calendar_get_meetings(?string $userId = null, ?string $sessionId = null): array
{
    $db = calendar_get_db();
    if (!$db) {
        return [];
    }

    calendar_ensure_tables($db);

    $sql = "SELECT * FROM mh_meetings";
    $conditions = [];
    $params = [];

    if ($userId !== null && $userId !== '') {
        $conditions[] = "created_by_user = :user_id";
        $params[':user_id'] = $userId;
    }

    if ($sessionId !== null && $sessionId !== '') {
        $conditions[] = "(session_id = :session_id OR session_id IS NULL)";
        $params[':session_id'] = $sessionId;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY scheduled_for_utc IS NULL, scheduled_for_utc ASC, created_at_utc DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!is_array($rows)) {
        return [];
    }

    return $rows;
}

function calendar_notify_tock(PDO $db, int $meetingId, string $overrideTitle = '', string $overrideScheduled = '', string $overrideRoomId = '', string $overrideInviteUrl = '', string $overrideUserId = '', string $overrideSessionId = ''): void
{
    static $recursionDepth = 0;
    if ($recursionDepth > 1) return;
    $recursionDepth++;

    $stmt = $db->prepare("SELECT * FROM mh_meetings WHERE id = :id");
    $stmt->execute([':id' => $meetingId]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$meeting || !is_array($meeting)) {
        $recursionDepth--;
        return;
    }

    if ((int)$meeting['tock_notified'] === 1 && $overrideUserId === '') {
        $recursionDepth--;
        return;
    }

    $title = $overrideTitle !== '' ? $overrideTitle : ($meeting['title'] ?? 'MetaHumans Meeting');
    $scheduledText = $overrideScheduled !== '' ? $overrideScheduled : ($meeting['scheduled_for_text'] ?? '');
    $roomId = $overrideRoomId !== '' ? $overrideRoomId : ($meeting['room_id'] ?? '');
    $inviteUrl = $overrideInviteUrl !== '' ? $overrideInviteUrl : ($meeting['invite_url'] ?? '');
    $userId = $overrideUserId !== '' ? $overrideUserId : ($meeting['created_by_user'] ?? '');
    $sessionId = $overrideSessionId !== '' ? $overrideSessionId : ($meeting['session_id'] ?? '');
    $personaMode = isset($meeting['persona_mode']) ? (string)$meeting['persona_mode'] : '';

    // Construction of the query string for the Tock call
    $parts = [];
    $parts[] = "Create a reminder for a Meta Humans meeting.";
    if ($title !== '') {
        $parts[] = "Title: " . $title . ".";
    }
    if ($scheduledText !== '') {
        $parts[] = "Scheduled for: " . $scheduledText . ".";
    }
    if ($roomId !== '') {
        $parts[] = "Room ID: " . $roomId . ".";
    }
    if ($inviteUrl !== '') {
        $parts[] = "Invite link: " . $inviteUrl . ".";
    }
    if ($userId !== '') {
        $parts[] = "User: " . $userId . ".";
    }
    if ($sessionId !== '') {
        $parts[] = "Meta Human session: " . $sessionId . ".";
    }
    $query = implode(" ", $parts);

    $tockUrl = getenv('TOCK_URL');
    if (!is_string($tockUrl) || trim($tockUrl) === '') {
        $tockUrl = 'https://meta.superhumans.one/tock/v1/route';
    }

    $sanitizeId = function (string $s): string {
        $s = trim($s);
        $s = preg_replace('/\s+/', '_', $s);
        $s = preg_replace('/[^A-Za-z0-9:_\\-\\.]+/', '', $s);
        return (string)$s;
    };

    $tenantId = $userId !== '' ? ('user:' . $userId) : '';
    $tenantId = $sanitizeId($tenantId);

    $personaId = $userId !== '' ? ('MH-' . $userId) : '';
    $personaId = $sanitizeId($personaId);

    $metaHumanId = $personaId !== '' ? ('meta:' . $personaId) : '';

    if ($tenantId === '' || strpos($tenantId, 'user:') !== 0) {
        $recursionDepth--;
        return;
    }
    if ($personaId === '' || $metaHumanId === '' || strpos($metaHumanId, 'meta:') !== 0) {
        $recursionDepth--;
        return;
    }

    $signKeyId = '';
    $signSecret = '';
    try {
        $cfgPath = '/data/config/tock-signing.json';
        if (is_file($cfgPath)) {
            $raw = (string)@file_get_contents($cfgPath);
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $signKeyId = isset($decoded['key_id']) ? trim((string)$decoded['key_id']) : '';
                $signSecret = isset($decoded['secret']) ? trim((string)$decoded['secret']) : '';
            }
        }
    } catch (Throwable) {
        $signKeyId = '';
        $signSecret = '';
    }

    $payload = [
        'channel' => 'calendar',
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'user_id' => $userId !== '' ? $userId : '',
        'input' => ['text' => $query],
    ];

    $ch = curl_init($tockUrl);
    if ($ch === false) {
        $recursionDepth--;
        return;
    }

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || $body === '') {
        $recursionDepth--;
        return;
    }
    $headers = ['Content-Type: application/json'];
    if ($signKeyId !== '' && $signSecret !== '') {
        $ts = (string)time();
        $sig = hash_hmac('sha256', $ts . "\n" . $body, $signSecret);
        $headers[] = 'X-MH-KeyId: ' . $signKeyId;
        $headers[] = 'X-MH-Timestamp: ' . $ts;
        $headers[] = 'X-MH-Signature: ' . $sig;
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $ok = false;
    if (is_string($resp) && $resp !== '') {
        $decoded = json_decode($resp, true);
        if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === true) {
            $ok = true;
        }
    }
    $ch = null;

    if ($ok && $overrideUserId === '') {
        $update = $db->prepare("UPDATE mh_meetings SET tock_notified = 1 WHERE id = :id");
        $update->execute([':id' => $meetingId]);

        // Notify attendees as well
        try {
            $stmt = $db->prepare("SELECT username, name_display, email, phone FROM mh_meeting_attendees WHERE meeting_id = ?");
            $stmt->execute([$meetingId]);
            $attendees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($attendees as $a) {
                $a_user = trim((string)($a['username'] ?? ''));
                if ($a_user === '' || $a_user === $userId) continue;
                
                // Construct attendee-specific info for Tock recursion
                calendar_notify_tock($db, $meetingId, $title, $scheduledText, $roomId, $inviteUrl, $a_user, '');
            }
        } catch (Throwable) {}
    }
    $recursionDepth--;
}
