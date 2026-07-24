<?php
http_response_code(307);
$qs = isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '';
$target = '/hub/genesis/persona-images.php' . ($qs !== '' ? ('?' . $qs) : '');
header('Location: ' . $target);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Moved: ' . $target;
