<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib.php';

$ctx = mh_widget_require_auth();
$username = (string)$ctx['username'];
$selected = (string)$ctx['persona_id'];

$personas = mh_widget_list_personas($username, $selected);
foreach ($personas as &$p) {
    unset($p['selected']);
}
unset($p);

mh_widget_json([
    'success' => true,
    'personas' => $personas,
]);

