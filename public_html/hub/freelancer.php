<?php
/**
 * Meta Humans Freelancer Marketplace (Rent a Human)
 * - Freelancers list services.
 * - Clients rent humans.
 * - Meeting system integration.
 * - Token usage for interactions.
 */

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';
require_once __DIR__ . '/equity/db.php';

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
$tokens = $_SESSION['tokens'] ?? 0;
$user_role = $_SESSION['role'] ?? 'user'; // 'user' or 'freelancer'

try {
    $pdo = getEquityConnectionStrict();
} catch (Throwable $e) {
    die("Database Connection Error");
}

// Self-healing: Ensure tables exist
try {
    // Services table
    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        freelancer_username VARCHAR(255) NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        price DECIMAL(10, 2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Meetings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS meetings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_id INT NOT NULL,
        client_username VARCHAR(255) NOT NULL,
        freelancer_username VARCHAR(255) NOT NULL,
        start_time DATETIME,
        status ENUM('scheduled', 'live', 'completed') DEFAULT 'scheduled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

// Handle Actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'list_service') {
        $title = $_POST['title'] ?? '';
        $price = $_POST['price'] ?? 0;
        $desc = $_POST['description'] ?? '';
        
        if ($title && $price > 0) {
            $stmt = $pdo->prepare("INSERT INTO services (freelancer_username, title, description, price) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $title, $desc, $price]);
            $message = "Service listed successfully!";
        } else {
            $error = "Invalid service details.";
        }
    }
    elseif ($action === 'book_service') {
        $service_id = $_POST['service_id'] ?? 0;
        // Mock Stripe Payment from Client
        // In real app: Charge Client $price
        
        $stmt = $pdo->prepare("SELECT * FROM services WHERE id = ?");
        $stmt->execute([$service_id]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($service) {
            $stmt = $pdo->prepare("INSERT INTO meetings (service_id, client_username, freelancer_username, status) VALUES (?, ?, ?, 'scheduled')");
            $stmt->execute([$service['id'], $username, $service['freelancer_username']]);
            $message = "Service booked! Meeting scheduled.";
        }
    }
    elseif ($action === 'start_meeting') {
        $meeting_id = $_POST['meeting_id'] ?? 0;
        // Freelancer pays $15/hour
        // Mock Payment: Charge Freelancer $15 (or deduct from earnings)
        
        $stmt = $pdo->prepare("UPDATE meetings SET status = 'live', start_time = NOW() WHERE id = ?");
        $stmt->execute([$meeting_id]);
        
        // Redirect to meeting room
        header("Location: /hub/meeting.php?id=$meeting_id");
        exit;
    }
}

// Fetch Services
$stmt = $pdo->query("SELECT * FROM services ORDER BY created_at DESC");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch My Meetings
$stmt = $pdo->prepare("SELECT * FROM meetings WHERE client_username = ? OR freelancer_username = ?");
$stmt->execute([$username, $username]);
$my_meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rent a Human | Freelancer Marketplace</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.freelancer-page main.main-content { color: rgba(255,255,255,0.9); font-family: var(--font-primary, 'Rajdhani', sans-serif); }

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
        
        h2 { margin-top: 40px; }

        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .card {
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            backdrop-filter: blur(10px);
            transition: transform 0.2s;
        }
        .card:hover { transform: translateY(-5px); }

        .price-tag {
            font-size: 1.5rem;
            color: #fff;
            font-weight: bold;
            margin: 10px 0;
        }

        .btn {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
            padding: 8px 16px;
            cursor: pointer;
            text-transform: uppercase;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { background: var(--primary); color: #000; }

        .form-group { margin-bottom: 15px; }
        input, textarea {
            width: 100%;
            padding: 10px;
            background: rgba(0,0,0,0.3);
            border: 1px solid #333;
            color: #fff;
            border-radius: 4px;
        }
    </style>
</head>
<body class="freelancer-page">
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content freelancer-content">
        <h1>Rent a Human</h1>
        <p>Hire experts for live sessions in the Meta Verse.</p>

        <?php if ($message): ?><div style="color: #0f0; margin-bottom: 20px;"><?php echo $message; ?></div><?php endif; ?>
        <?php if ($error): ?><div style="color: #f00; margin-bottom: 20px;"><?php echo $error; ?></div><?php endif; ?>

        <!-- List a Service (Freelancer Onboarding) -->
        <div class="card" style="margin-bottom: 40px;">
            <h2>List Your Service</h2>
            <form method="POST">
                <input type="hidden" name="action" value="list_service">
                <div class="form-group">
                    <input type="text" name="title" placeholder="Service Title (e.g. 1-hour Coding Session)" required>
                </div>
                <div class="form-group">
                    <textarea name="description" placeholder="Describe what you offer..." rows="3"></textarea>
                </div>
                <div class="form-group">
                    <input type="number" name="price" placeholder="Price ($)" step="0.01" required>
                </div>
                <button type="submit" class="btn">List Service</button>
            </form>
        </div>

        <!-- Available Services -->
        <h2>Available Humans</h2>
        <div class="service-grid">
            <?php foreach ($services as $service): ?>
                <div class="card">
                    <h3><?php echo htmlspecialchars($service['title']); ?></h3>
                    <p style="color: #aaa;">by <?php echo htmlspecialchars($service['freelancer_username']); ?></p>
                    <p><?php echo htmlspecialchars($service['description']); ?></p>
                    <div class="price-tag">$<?php echo number_format($service['price'], 2); ?></div>
                    <form method="POST">
                        <input type="hidden" name="action" value="book_service">
                        <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
                        <button type="submit" class="btn">Rent Now</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- My Meetings -->
        <h2>My Meetings</h2>
        <div class="service-grid">
            <?php foreach ($my_meetings as $meeting): ?>
                <div class="card">
                    <h3>Meeting #<?php echo $meeting['id']; ?></h3>
                    <p>Status: <span style="color: <?php echo $meeting['status'] === 'live' ? '#0f0' : '#aaa'; ?>"><?php echo strtoupper($meeting['status']); ?></span></p>
                    <p>With: <?php echo htmlspecialchars($meeting['client_username'] === $username ? $meeting['freelancer_username'] : $meeting['client_username']); ?></p>
                    
                    <?php if ($meeting['status'] === 'scheduled'): ?>
                        <?php if ($meeting['freelancer_username'] === $username): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="start_meeting">
                                <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                                <button type="submit" class="btn">Start Meeting ($15 Fee)</button>
                            </form>
                        <?php else: ?>
                            <button class="btn" disabled>Waiting for Host</button>
                        <?php endif; ?>
                    <?php elseif ($meeting['status'] === 'live'): ?>
                        <a href="/hub/meeting.php?id=<?php echo $meeting['id']; ?>" class="btn">Join Meeting</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
