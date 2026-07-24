<?php
declare(strict_types=1);

require_once __DIR__ . '/../widget/_lib.php';

$ctx = mh_widget_require_auth();

header('Location: /hub/genesis/personas.php', true, 302);
exit;

