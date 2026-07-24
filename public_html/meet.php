<?php
require_once __DIR__ . '/.cue/cue.php';

$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
header('Location: /hub/meet/' . $qs, true, 302);
exit;
