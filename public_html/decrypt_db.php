<?php
define('CUE_START_TIME', microtime(true));
require_once __DIR__ . '/.cue/cue.php';

// Force load database module
cue_autoload('database');

// Load configurations
$configs = database_loadConfigurations();

echo "--- DECRYPTED CONFIGS ---\n";
foreach ($configs as $id => $conf) {
    echo "Name: " . $conf['name'] . "\n";
    echo "User: " . $conf['username'] . "\n";
    echo "Pass: " . $conf['password'] . "\n";
    echo "Host: " . $conf['host'] . "\n";
    echo "Port: " . $conf['port'] . "\n";
    echo "DB:   " . $conf['database'] . "\n";
    echo "-------------------------\n";
}
