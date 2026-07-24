<?php
/**
 * Metahumans.one Token Top-up API Endpoint
 *
 * Receives requests from WHMCS to credit user accounts.
 *
 * Security:
 * - Requires X-API-KEY header
 * - Validates input parameters
 */

// Define the API Key (In production, load this from a secure config file or .env)
// For now, we will define a placeholder. YOU MUST CHANGE THIS.
define('METAHUMANS_API_KEY', 'change_this_to_a_secure_random_string_matches_whmcs');

// Enable error logging for debugging (disable in production or log to file)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';
if (function_exists('cue_autoload')) {
    cue_autoload('database');
}

// 1. Verify Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// 2. Verify API Key
$headers = getallheaders();
$apiKey = $headers['X-API-KEY'] ?? $headers['X-Api-Key'] ?? '';

if ($apiKey !== METAHUMANS_API_KEY) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden: Invalid API Key']);
    exit;
}

// 3. Parse JSON Body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON Payload']);
    exit;
}

// 4. Validate Parameters
$email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
$tokens = filter_var($input['tokens'] ?? 0, FILTER_VALIDATE_INT);
$transactionId = $input['transaction_id'] ?? null;
$serviceId = $input['service_id'] ?? null;

if (!$email || $tokens <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email or token amount']);
    exit;
}

// 5. Connect to Database (MariaDB)
try {
    $pdo = database_getConnectionById('biometrics');

    // 6. Transaction: Update Balance & Log Transaction
    $pdo->beginTransaction();

    // A. Check if user exists (create if not - optional, but safer to assume user exists)
    // For now, we assume the user must exist. If not, we could create a stub.
    $stmt = $pdo->prepare("SELECT user_id FROM user_credits WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $userId = 0;
    if ($user) {
        $userId = $user['user_id'];
        // Update existing
        $updateStmt = $pdo->prepare("UPDATE user_credits SET balance = balance + ?, updated_at = NOW() WHERE user_id = ?");
        $updateStmt->execute([$tokens, $userId]);
    } else {
        // User not found in credit table - try to find in main users table?
        // Or just create a new entry in user_credits if you have a separate users table
        // For this example, we'll insert a new row, assuming user_id might be auto-increment or mapped
        // Let's assume we need to look up the real user_id from your main 'users' table first
        
        // Placeholder for main user lookup:
        // $mainUserStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        // $mainUserStmt->execute([$email]);
        // $mainUser = $mainUserStmt->fetch();
        
        // If user doesn't exist in system at all, fail? Or create?
        // For safety, let's fail if we can't map the email to a user.
        // BUT, since I don't know your exact users table, I will UPSERT into user_credits assuming user_id 0 is a placeholder or you fix this logic.
        
        // BETTER: Insert into user_credits. If user_id is FK, this might fail if I guess.
        // Let's assume the email is the key for now or we create a new record.
        // To be safe, we will return an error if user not found, prompting manual intervention.
        
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found for email: ' . $email]);
        exit;
    }

    // B. Log the transaction
    $logStmt = $pdo->prepare("INSERT INTO credit_transactions (user_id, amount, source, transaction_reference, description) VALUES (?, ?, 'whmcs', ?, ?)");
    $logStmt->execute([$userId, $tokens, $transactionId, "WHMCS Service ID: $serviceId"]);

    $pdo->commit();

    // 7. Success Response
    echo json_encode([
        'success' => true,
        'message' => 'Tokens credited successfully',
        'new_balance' => $tokens // Security: don't reveal total balance if not needed
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
    exit;
}
