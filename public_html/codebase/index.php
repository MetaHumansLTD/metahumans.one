<?php
// Secure SSH Interface for promptengine.one
// Uses local keys to connect to remote GPU server

// Load CUE Framework & Session
require_once __DIR__ . '/../.cue/cue.php';

if (session_status() === PHP_SESSION_NONE) {
    if (function_exists('startSecureSession')) {
        startSecureSession();
    } else {
        session_start();
    }
}

// Authentication Check: KripzMaster Only
$userRole = $_SESSION['mh_auth_role'] ?? '';
if (stripos($userRole, 'KripzMaster') === false) {
    // Log unauthorized attempt
    error_log("Unauthorized access attempt to /codebase/ from user: " . ($_SESSION['mh_auth_user'] ?? 'guest'));
    
    // Redirect or Show Error
    http_response_code(403);
    die("<h1>Access Denied</h1><p>You must be a KripzMaster to access this area.</p><p><a href='/auth'>Login Here</a></p>");
}

$host = 'promptengine.one';
$keyPath = '/home/onemeta/ssh/id_rsa';
$users = ['root', 'ubuntu', 'onemeta', 'plugnmeet'];

$selectedUser = $_POST['user'] ?? 'root';
$command = $_POST['command'] ?? 'nvidia-smi';

$output = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($command)) {
    if (!in_array($selectedUser, $users)) {
        $output = "Invalid user";
    } else {
        $sshCmd = "ssh -o StrictHostKeyChecking=no -i $keyPath $selectedUser@$host " . escapeshellarg($command);
        exec($sshCmd . ' 2>&1', $outLines, $returnVar);
        $output = implode("\n", $outLines);
        if ($returnVar !== 0) {
            $output .= "\n\nExit Code: $returnVar";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GPU Server Interface</title>
    <style>
        body { font-family: monospace; background: #1e1e1e; color: #d4d4d4; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        textarea { width: 100%; height: 100px; background: #252526; color: #d4d4d4; border: 1px solid #3c3c3c; padding: 10px; }
        pre { background: #000; padding: 15px; border: 1px solid #333; white-space: pre-wrap; }
        button { padding: 10px 20px; background: #0e639c; color: white; border: none; cursor: pointer; }
        button:hover { background: #1177bb; }
        select { padding: 10px; background: #252526; color: white; border: 1px solid #3c3c3c; }
    </style>
</head>
<body>
<div class="container">
    <h1>PromptEngine.one Control</h1>
    <form method="post">
        <select name="user">
            <?php foreach ($users as $u): ?>
                <option value="<?= $u ?>" <?= $selectedUser === $u ? 'selected' : '' ?>><?= $u ?></option>
            <?php endforeach; ?>
        </select>
        <br><br>
        <textarea name="command" placeholder="Enter command (e.g. nvidia-smi, ls -la /opt)"><?= htmlspecialchars($command) ?></textarea>
        <br><br>
        <button type="submit">Run Command</button>
    </form>
    
    <?php if ($output): ?>
        <h2>Output:</h2>
        <pre><?= htmlspecialchars($output) ?></pre>
    <?php endif; ?>
    
    <h3>Common Commands:</h3>
    <ul>
        <li><code>nvidia-smi</code> - Check GPU Status</li>
        <li><code>systemctl status plugnmeet-ai.service</code> - Check AI Server Status</li>
        <li><code>tail -n 50 /var/log/nginx/promptengine.one_access.log</code> - Check Access Logs</li>
    </ul>
</div>
</body>
</html>
