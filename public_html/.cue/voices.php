<?php

function voices_get_db(): ?PDO {
    if (!function_exists('cue_autoload')) {
        return null;
    }
    $database = cue_autoload('database');
    if (!$database || !method_exists($database, 'getContextAwareConnection')) {
        return null;
    }
    try {
        return $database->getContextAwareConnection();
    } catch (Throwable $e) {
        return null;
    }
}

function voices_ensure_tables(PDO $db): void {
    $sql = "
        CREATE TABLE IF NOT EXISTS mh_persona_voices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(191) NULL,
            persona_key VARCHAR(191) NOT NULL,
            voice_id VARCHAR(191) NULL,
            task_type VARCHAR(64) NULL,
            ref_audio_url TEXT NULL,
            ref_text TEXT NULL,
            instructions TEXT NULL,
            created_at_utc DATETIME NOT NULL,
            updated_at_utc DATETIME NOT NULL,
            UNIQUE KEY uniq_user_persona (user_id, persona_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $db->exec($sql);
}

function voices_save_profile(array $data): array {
    $db = voices_get_db();
    if (!$db) {
        return ['status' => 'error', 'message' => 'database_unavailable'];
    }
    voices_ensure_tables($db);
    $userId = isset($data['user_id']) ? (string)$data['user_id'] : null;
    $personaKey = isset($data['persona_key']) ? (string)$data['persona_key'] : '';
    if ($personaKey === '') {
        return ['status' => 'error', 'message' => 'persona_key_required'];
    }
    $voiceId = isset($data['voice_id']) ? (string)$data['voice_id'] : null;
    $taskType = isset($data['task_type']) ? (string)$data['task_type'] : null;
    $refAudioUrl = isset($data['ref_audio_url']) ? (string)$data['ref_audio_url'] : null;
    $refText = isset($data['ref_text']) ? (string)$data['ref_text'] : null;
    $instructions = isset($data['instructions']) ? (string)$data['instructions'] : null;
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $sql = "
        INSERT INTO mh_persona_voices (
            user_id,
            persona_key,
            voice_id,
            task_type,
            ref_audio_url,
            ref_text,
            instructions,
            created_at_utc,
            updated_at_utc
        ) VALUES (
            :user_id,
            :persona_key,
            :voice_id,
            :task_type,
            :ref_audio_url,
            :ref_text,
            :instructions,
            :created_at_utc,
            :updated_at_utc
        )
        ON DUPLICATE KEY UPDATE
            voice_id = VALUES(voice_id),
            task_type = VALUES(task_type),
            ref_audio_url = VALUES(ref_audio_url),
            ref_text = VALUES(ref_text),
            instructions = VALUES(instructions),
            updated_at_utc = VALUES(updated_at_utc)
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':user_id' => $userId,
        ':persona_key' => $personaKey,
        ':voice_id' => $voiceId,
        ':task_type' => $taskType,
        ':ref_audio_url' => $refAudioUrl,
        ':ref_text' => $refText,
        ':instructions' => $instructions,
        ':created_at_utc' => $now->format('Y-m-d H:i:s'),
        ':updated_at_utc' => $now->format('Y-m-d H:i:s'),
    ]);
    return ['status' => 'success'];
}

function voices_get_profile(?string $userId, string $personaKey): ?array {
    $db = voices_get_db();
    if (!$db) {
        return null;
    }
    voices_ensure_tables($db);
    $sql = "SELECT * FROM mh_persona_voices WHERE persona_key = :persona_key";
    $params = [':persona_key' => $personaKey];
    if ($userId !== null && $userId !== '') {
        $sql .= " AND (user_id = :user_id OR user_id IS NULL)";
        $params[':user_id'] = $userId;
    }
    $sql .= " ORDER BY user_id IS NULL ASC LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !is_array($row)) {
        return null;
    }
    return $row;
}

function voices_generate_speech(string $text, array $options = []): array {
    $endpoint = function_exists('cue_getTtsEndpoint') ? cue_getTtsEndpoint() : (defined('CUE_TTS_HTTP_ENDPOINT') ? CUE_TTS_HTTP_ENDPOINT : 'http://127.0.0.1:32101/tts');
    $payload = [
        'text' => $text,
        'voice' => $options['voice'] ?? null,
        'language' => $options['language'] ?? null,
        'instructions' => $options['instructions'] ?? null,
        'task_type' => $options['task_type'] ?? null,
        'ref_audio' => $options['ref_audio'] ?? null,
        'ref_text' => $options['ref_text'] ?? null,
    ];
    $payload = array_filter($payload, function ($v) {
        return $v !== null;
    });
    $ch = curl_init($endpoint);
    if ($ch === false) {
        return ['status' => 'error', 'message' => 'curl_init_failed'];
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) {
        return ['status' => 'error', 'message' => 'curl_error', 'details' => $err];
    }
    if ($code < 200 || $code >= 300 || $body === false) {
        return ['status' => 'error', 'message' => 'http_error', 'code' => $code];
    }
    $audioBase64 = base64_encode($body);
    return ['status' => 'success', 'audio_base64' => $audioBase64];
}

