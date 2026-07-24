<?php
require_once __DIR__ . '/.cue/cue.php';

try {
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    $pdo = database_getConnectionById('db_equity_dedicated');
} catch (Throwable $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

// Create Marketplace Table
// Allows users to list equity units for sale
$sql = "CREATE TABLE IF NOT EXISTS equity_market (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_username VARCHAR(255) NOT NULL,
    class_id INT NOT NULL,
    units_available BIGINT NOT NULL,
    price_per_unit DECIMAL(10,2) NOT NULL, -- Price per coin/unit
    status ENUM('active', 'sold', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    FOREIGN KEY (class_id) REFERENCES equity_classes(id)
)";

try {
    $pdo->exec($sql);
    echo "Table 'equity_market' created/checked.\n";
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
?>
