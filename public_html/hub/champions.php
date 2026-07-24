<?php
/**
 * Meta Humans Champions (formerly Affiliate)
 * Champions can create video reels in Codespaces for distribution.
 * Cost: 50 tokens per video.
 */

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';

// Ensure secure session
if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in
if (!isset($_SESSION['mh_auth_user'])) {
    header('Location: /auth/login.php');
    exit;
}

$username = $_SESSION['mh_auth_user'];
if (function_exists('mh_refresh_session_token_balance')) {
    mh_refresh_session_token_balance((string)$username, 30);
}
$tokens = $_SESSION['tokens'] ?? 0;
$is_champion = $_SESSION['is_champion'] ?? false;

$pdo = null;
try {
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    if (function_exists('database_getContextAwareConnection')) {
        $pdo = database_getContextAwareConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS mh_user_flags (
            username VARCHAR(255) NOT NULL PRIMARY KEY,
            is_champion TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $pdo->prepare("SELECT is_champion FROM mh_user_flags WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $val = $stmt->fetchColumn();
        if ($val === false) {
            $pdo->prepare("INSERT INTO mh_user_flags (username, is_champion) VALUES (?, 0)")->execute([$username]);
            $is_champion = false;
        } else {
            $is_champion = ((int)$val) === 1;
        }
        $_SESSION['is_champion'] = $is_champion;
    }
} catch (Throwable $e) {
}

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'join_champions') {
        if ($pdo instanceof PDO) {
            $pdo->prepare("INSERT INTO mh_user_flags (username, is_champion) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_champion=1")->execute([$username]);
        }
        $_SESSION['is_champion'] = true;
        $is_champion = true;
    }
    elseif ($action === 'create_reel') {
        if (function_exists('mh_charge_service_tokens')) {
            $pricing = mh_charge_service_tokens((string)$username, 'champions:reel', 1, ['source' => 'hub/champions', 'action' => 'create_reel'], 50);
            if (!empty($pricing['success'])) {
                if (function_exists('mh_refresh_session_token_balance')) {
                    mh_refresh_session_token_balance((string)$username, 1);
                }
                $tokens = $_SESSION['tokens'] ?? $tokens;
                $debited = isset($pricing['debited']) ? (int)$pricing['debited'] : 0;
                $message = "Video Reel created successfully! " . number_format($debited > 0 ? $debited : 50) . " tokens deducted.";
            } else {
                $error = isset($pricing['error']) && $pricing['error'] === 'insufficient_tokens'
                    ? "Insufficient tokens. You need 50 tokens to create a reel."
                    : "Token debit failed.";
            }
        } else {
            $error = "Token debit failed.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Champions Dashboard | Meta Humans</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.champions-page main.main-content { color: #ffffff; font-family: var(--font-primary, 'Rajdhani', sans-serif); }

        .main-content {
            flex: 1;
            padding: 40px;
            background: transparent !important;
            margin: 0 auto;
            max-width: 1200px;
            width: 100%;
        }
        
        /* Footer Adjustment */
        footer, .cue-global-footer {
            border-top: 1px solid var(--border);
            background: var(--bg-dark);
            position: relative;
            z-index: 950;
            width: 100%;
        }

        h1, h2, h3 {
            font-family: 'Orbitron', sans-serif;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 20px;
        }
        p, li, .card, .alert { color: #ffffff; }
        a { color: var(--primary); }

        .card {
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 30px;
            backdrop-filter: blur(10px);
            max-width: 800px;
            margin-bottom: 30px;
        }

        .btn {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
            padding: 12px 25px;
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            background: var(--primary);
            color: #000;
            box-shadow: 0 0 15px rgba(0, 212, 255, 0.4);
        }

        .token-display {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: #fff;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .alert-success { background: rgba(0, 255, 0, 0.1); border: 1px solid #0f0; color: #0f0; }
        .alert-error { background: rgba(255, 0, 0, 0.1); border: 1px solid #f00; color: #f00; }
    </style>
</head>
<body class="champions-page">
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content champions-content">
        <h1>Champions Program</h1>

        <?php if (isset($message)): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (!$is_champion): ?>
            <!-- Onboarding -->
            <div class="card">
                <h2>Become a Champion</h2>
                <p>Join the elite Meta Humans Champions program. Create content, distribute video reels, and earn rewards.</p>
                <p>As a Champion, you can utilize Codespaces to generate high-quality video reels.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="join_champions">
                    <button type="submit" class="btn">Join Now</button>
                </form>
            </div>
        <?php else: ?>
            <!-- Dashboard -->
            <div class="card">
                <h2>Create Video Reel</h2>
                <div class="token-display">
                    Your Balance: <strong><?php echo number_format($tokens); ?> Tokens</strong>
                </div>
                <p>Generate a new video reel in your Codespace.</p>
                <p><strong>Cost: 50 Tokens</strong></p>
                
                <form method="POST">
                    <input type="hidden" name="action" value="create_reel">
                    <button type="submit" class="btn" <?php echo ($tokens < 50) ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
                        Create Reel (-50 Tokens)
                    </button>
                </form>
                
                <?php if ($tokens < 50): ?>
                    <p style="color: #ff4444; margin-top: 10px;">Insufficient tokens. <a href="/hub/genesis/tokenization.php" style="color: var(--primary);">Buy more tokens</a>.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Your Reels</h2>
                <p>No reels created yet.</p>
                <!-- List reels here -->
            </div>
        <?php endif; ?>
    </main>

    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
