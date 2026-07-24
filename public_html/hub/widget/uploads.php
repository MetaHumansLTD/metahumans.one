<?php
declare(strict_types=1);

require_once __DIR__ . '/_lib.php';

mh_widget_require_auth();

mh_widget_json([
    'success' => false,
    'error' => 'not_implemented',
], 501);

