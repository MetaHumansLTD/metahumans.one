<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib.php';

$ctx = mh_widget_require_auth();
$username = (string)$ctx['username'];
$selected = (string)$ctx['persona_id'];

$personaId = isset($_GET['persona_id']) ? trim((string)$_GET['persona_id']) : '';
if ($personaId === '') {
    mh_widget_json(['success' => false, 'error' => 'missing_persona_id'], 400);
    exit;
}

$personas = mh_widget_list_personas($username, $selected);
$found = null;
foreach ($personas as $p) {
    if (($p['id'] ?? '') === $personaId) {
        $found = $p;
        break;
    }
}
if (!is_array($found)) {
    $found = [
        'id' => $personaId,
        'name' => $personaId,
        'avatar_url' => '/hub/genesis/persona-images.php?persona=' . rawurlencode($personaId),
        'capabilities' => [
            'realtime' => true,
            'voice_text' => true,
            'text_only' => true,
        ],
    ];
}

mh_widget_json([
    'success' => true,
    'persona' => $found,
]);
