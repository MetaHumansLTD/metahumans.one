<?php
/**
 * PlugNMeet Helper Functions
 */

require_once __DIR__ . '/../../.cue/cue.php';

function pnm_load_config(): array
{
    $configFile = '/data/config/plugnmeet.json';
    if (function_exists('getDataPath')) {
        $configFile = rtrim(trim((string)call_user_func('getDataPath')), '/') . '/config/plugnmeet.json';
    }
    if (is_file($configFile)) {
        $raw = file_get_contents($configFile);
        if ($raw !== false) {
            $cfg = json_decode($raw, true);
            if (is_array($cfg)) {
                return $cfg;
            }
        }
    }
    return [];
}

function pnm_cfg_get(array $cfg, string $key, $default)
{
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

function pnm_get_base_url(): string
{
    $cfg = pnm_load_config();
    $url = (string) pnm_cfg_get($cfg, 'url', 'https://superhumans.one/plugnmeet');
    return $url !== '' ? $url : 'https://superhumans.one/plugnmeet';
}

function pnm_get_public_url(): string
{
    $cfg = pnm_load_config();
    $url = (string) pnm_cfg_get($cfg, 'public_url', 'https://metahumans.one/meet');
    return $url !== '' ? $url : 'https://metahumans.one/meet';
}

function pnm_get_api_key(): string
{
    [$k] = pnm_get_credentials();
    return $k;
}

function pnm_get_api_secret(): string
{
    [, $s] = pnm_get_credentials();
    return $s;
}

function pnm_get_credentials(): array
{
    static $cached = null;
    if (is_array($cached) && isset($cached[0], $cached[1])) {
        return $cached;
    }

    $cfg = pnm_load_config();
    $apiKeyEnc = isset($cfg['plugnmeet_api_key']) ? (string)$cfg['plugnmeet_api_key'] : '';
    $apiSecretEnc = isset($cfg['plugnmeet_api_secret']) ? (string)$cfg['plugnmeet_api_secret'] : '';
    if ($apiKeyEnc === '' || $apiSecretEnc === '') {
        throw new RuntimeException('PlugNMeet credentials missing');
    }

    if (function_exists('cue_autoload')) {
        call_user_func('cue_autoload', 'security');
        call_user_func('cue_autoload', 'paths');
    }
    if (!function_exists('security_decryptValue')) {
        throw new RuntimeException('Security module unavailable');
    }

    $keyPath = function_exists('paths_getEncryptionKeyPath') ? call_user_func('paths_getEncryptionKeyPath') : '/data/security/app.key';
    $keyRaw = is_file($keyPath) ? file_get_contents($keyPath) : false;
    $key = is_string($keyRaw) ? trim($keyRaw) : '';
    if ($key === '') {
        throw new RuntimeException('Encryption key missing');
    }

    $apiKey = call_user_func('security_decryptValue', $apiKeyEnc, $key);
    $apiSecret = call_user_func('security_decryptValue', $apiSecretEnc, $key);
    if (!is_string($apiKey) || $apiKey === '' || !is_string($apiSecret) || $apiSecret === '') {
        throw new RuntimeException('Failed to decrypt PlugNMeet credentials');
    }

    $cached = [$apiKey, $apiSecret];
    return $cached;
}

function pnm_get_feature_flags(): array
{
    $cfg = pnm_load_config();
    $features = is_array($cfg['features'] ?? null) ? $cfg['features'] : [];
    $langs = is_array($cfg['languages'] ?? null) ? $cfg['languages'] : [];

    $defaultSpoken = [
        'en-US',
        'af-ZA',
        'ar-SA',
        'bg-BG',
        'bn-BD',
        'cs-CZ',
        'da-DK',
        'de-DE',
        'el-GR',
        'es-ES',
        'et-EE',
        'fa-IR',
        'fi-FI',
        'fr-FR',
        'he-IL',
        'hi-IN',
        'hr-HR',
        'hu-HU',
        'id-ID',
        'it-IT',
        'ja-JP',
        'ko-KR',
        'lt-LT',
        'lv-LV',
        'ms-MY',
        'nl-NL',
        'no-NO',
        'pl-PL',
        'pt-BR',
        'ro-RO',
        'ru-RU',
        'sk-SK',
        'sl-SI',
        'sv-SE',
        'th-TH',
        'tr-TR',
        'uk-UA',
        'vi-VN',
        'zh-CN',
        'zh-TW',
    ];

    $defaultTrans = [
        'en',
        'af',
        'am',
        'ar',
        'az',
        'bg',
        'bn',
        'bs',
        'ca',
        'cs',
        'da',
        'de',
        'el',
        'es',
        'et',
        'fa',
        'fi',
        'fr',
        'he',
        'hi',
        'hr',
        'hu',
        'id',
        'it',
        'ja',
        'ko',
        'lt',
        'lv',
        'ms',
        'nl',
        'no',
        'pl',
        'pt',
        'ro',
        'ru',
        'sk',
        'sl',
        'sv',
        'th',
        'tr',
        'uk',
        'vi',
        'zh-Hans',
        'zh-Hant',
    ];

    $spoken = $langs['allowed_spoken_langs'] ?? $defaultSpoken;
    $trans = $langs['allowed_trans_langs'] ?? $defaultTrans;
    if (!is_array($spoken)) $spoken = $defaultSpoken;
    if (!is_array($trans)) $trans = $defaultTrans;

    $defaultSubtitle = (string) ($langs['default_subtitle_lang'] ?? 'en');
    $defaultChat = (string) ($langs['default_chat_lang'] ?? 'en');

    $enableTt = (bool) ($features['enable_transcription_translation'] ?? true);
    $enableInsights = (bool) ($features['enable_insights'] ?? true);
    $enableAi = (bool) ($features['enable_ai'] ?? true);

    return [
        'enable_transcription_translation' => $enableTt,
        'enable_insights' => $enableInsights,
        'enable_ai' => $enableAi,
        'allowed_spoken_langs' => array_values(array_unique(array_filter($spoken, 'is_string'))),
        'allowed_trans_langs' => array_values(array_unique(array_filter($trans, 'is_string'))),
        'default_subtitle_lang' => $defaultSubtitle,
        'default_chat_lang' => $defaultChat,
    ];
}

function pnm_request_join_masked(string $path, array $body): array
{
    $url = rtrim(pnm_get_base_url(), '/') . $path;
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode request body');
    }

    $signature = hash_hmac('sha256', $json, pnm_get_api_secret());

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'API-KEY: ' . pnm_get_api_key(),
            'HASH-SIGNATURE: ' . $signature,
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        throw new RuntimeException('plugNmeet request failed: ' . $err);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $data = json_decode($response, true);
    if (!is_array($data)) {
        $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $head = substr($response, 0, 220);
        $head = preg_replace('/eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', '[jwt]', $head);
        $head = preg_replace('/[^\x20-\x7E\r\n\t]/', '.', $head);
        throw new RuntimeException('Invalid JSON response from plugNmeet (HTTP ' . $status . ', CT ' . ($ct ?: '') . ', json_err ' . json_last_error_msg() . ', head ' . $head . ')');
    }
    if ($status !== 200 || empty($data['status'])) {
        $msg = isset($data['msg']) ? $data['msg'] : 'unknown error';
        throw new RuntimeException('plugNmeet API error: ' . $msg);
    }

    return $data;
}

function pnm_build_features_payload(): array
{
    $flags = pnm_get_feature_flags();

    $ttEnabled = (bool) ($flags['enable_transcription_translation'] ?? true);
    $insEnabled = (bool) ($flags['enable_insights'] ?? true);
    $aiEnabled = (bool) ($flags['enable_ai'] ?? true);

    return [
        'speech_to_text_translation_features' => [
            'is_allow' => $ttEnabled,
            'is_allow_translation' => $ttEnabled,
            'max_num_speech_langs_allow_selecting' => 5,
            'max_num_tran_langs_allow_selecting' => 5,
        ],
        'insights_features' => [
            'is_allow' => $insEnabled,
            'transcription_features' => [
                'is_allow' => $insEnabled,
                'is_allow_translation' => $insEnabled,
                'max_num_speech_langs_allow_selecting' => 5,
                'max_num_tran_langs_allow_selecting' => 5,
            ],
            'chat_translation_features' => [
                'is_allow' => $insEnabled,
                'max_num_tran_langs_allow_selecting' => 5,
            ],
            'ai_features' => [
                'is_allow' => $aiEnabled,
                'ai_text_chat_features' => [
                    'is_allow' => $aiEnabled,
                ],
                'meeting_summarization_features' => [
                    'is_allow' => $aiEnabled,
                ],
            ],
        ],
    ];
}

function pnm_create_room_helper(string $roomId, string $roomTitle): void
{
    $featurePayload = pnm_build_features_payload();

    pnm_request_join_masked('/auth/room/create', [
        'room_id' => $roomId,
        'empty_timeout' => 60 * 60 * 24 * 30,
        'metadata' => [
            'room_title' => $roomTitle,
            'room_features' => array_merge([
                'allow_webcams' => true,
                'mute_on_start' => false,
                'allow_screen_share' => true,
                'allow_rtmp' => true,
                'admin_only_webcams' => false,
                'allow_view_other_webcams' => true,
                'allow_view_other_users_list' => true,
                'allow_polls' => true,
                'room_duration' => 0,
                'enable_analytics' => true,
                'allow_virtual_bg' => true,
                'allow_raise_hand' => true,
                'recording_features' => [
                    'is_allow' => true,
                    'is_allow_cloud' => true,
                    'is_allow_local' => true,
                    'enable_auto_cloud_recording' => false,
                    'only_record_admin_webcams' => false,
                ],
                'chat_features' => [
                    'allow_chat' => true,
                    'allow_file_upload' => true,
                    'max_file_size' => 50,
                    'allowed_file_types' => ['jpg', 'png', 'zip'],
                ],
                'shared_note_pad_features' => [
                    'allowed_shared_note_pad' => true,
                ],
                'whiteboard_features' => [
                    'allowed_whiteboard' => true,
                ],
                'external_media_player_features' => [
                    'allowed_external_media_player' => true,
                ],
                'waiting_room_features' => [
                    'is_active' => true,
                ],
                'breakout_room_features' => [
                    'is_allow' => true,
                    'allowed_number_rooms' => 6,
                ],
                'display_external_link_features' => [
                    'is_allow' => true,
                ],
                'ingress_features' => [
                    'is_allow' => true,
                ],
            ], $featurePayload),
        ],
    ]);
}

function pnm_get_join_token_helper(string $roomId, string $name, string $userId, bool $isAdmin): array
{
    $featurePayload = pnm_build_features_payload();

    return pnm_request_join_masked('/auth/room/getJoinToken', array_merge([
        'room_id' => $roomId,
        'user_info' => [
            'is_admin' => $isAdmin,
            'name' => $name,
            'user_id' => $userId,
        ],
    ], $featurePayload));
}

function pnm_get_active_room_info_helper(string $roomId): array
{
    return pnm_request_join_masked('/room/getActiveRoomInfo', [
        'room_id' => $roomId,
    ]);
}

function pnm_room_is_running_with_participants(array $activeInfo, int $minParticipants = 2): bool
{
    $room = $activeInfo['room'] ?? null;
    if (!is_array($room)) {
        return false;
    }
    $roomInfo = $room['room_info'] ?? null;
    if (!is_array($roomInfo)) {
        return false;
    }
    $isRunning = (bool)($roomInfo['is_running'] ?? false);
    if (!$isRunning) {
        return false;
    }
    $participants = $room['participants_info'] ?? null;
    if (!is_array($participants)) {
        return false;
    }
    return count($participants) >= max(1, $minParticipants);
}

function pnm_fetch_recordings_helper(array $roomIds, int $from = 0, int $limit = 50, string $orderBy = 'DESC'): array
{
    $roomIds = array_values(array_filter($roomIds, function ($v) {
        return is_string($v) && trim($v) !== '';
    }));
    if ($roomIds === []) {
        return ['status' => false, 'msg' => 'room_ids_required'];
    }
    $orderBy = strtoupper(trim($orderBy));
    if ($orderBy !== 'ASC') {
        $orderBy = 'DESC';
    }
    return pnm_request_join_masked('/auth/recording/fetch', [
        'room_ids' => $roomIds,
        'from' => max(0, $from),
        'limit' => max(1, min(200, $limit)),
        'order_by' => $orderBy,
    ]);
}

function pnm_get_recording_download_token_helper(string $recordId): array
{
    $recordId = trim($recordId);
    if ($recordId === '') {
        return ['status' => false, 'msg' => 'record_id_required'];
    }
    return pnm_request_join_masked('/auth/recording/getDownloadToken', [
        'record_id' => $recordId,
    ]);
}

function pnm_delete_recording_helper(string $recordId): array
{
    $recordId = trim($recordId);
    if ($recordId === '') {
        return ['status' => false, 'msg' => 'record_id_required'];
    }
    return pnm_request_join_masked('/auth/recording/delete', [
        'record_id' => $recordId,
    ]);
}

function pnm_build_recording_download_urls(string $token): array
{
    $token = trim($token);
    if ($token === '') {
        return [];
    }

    $base = rtrim(pnm_get_base_url(), '/');
    $primary = $base . '/download/recording/' . rawurlencode($token);

    $parsed = parse_url($base);
    if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
        return [$primary];
    }
    $hostBase = $parsed['scheme'] . '://' . $parsed['host'];
    if (isset($parsed['port'])) {
        $hostBase .= ':' . (int)$parsed['port'];
    }
    $fallback = rtrim($hostBase, '/') . '/download/recording/' . rawurlencode($token);
    if ($fallback === $primary) {
        return [$primary];
    }
    return [$primary, $fallback];
}
