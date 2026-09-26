<?php
require_once __DIR__ . '/../pages/config.php';

$db_host = DB_HOST;
$db_name = DB_NAME;
$db_user = DB_USER;
$db_pass = DB_PASS;

if ($db_name === '' || $db_user === '') {
    die('Database configuration is missing. Set DB_NAME and DB_USER environment variables.');
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $db_name)) {
    die('DB_NAME contains unsupported characters.');
}

try {
    $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS " . $db_name);
    $pdo->exec("USE " . $db_name);

    $pdo->exec("CREATE TABLE IF NOT EXISTS panel_admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        userid INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        price DECIMAL(10,2) NOT NULL,
        volume_gb INT NOT NULL,
        days_count INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        product_id INT,
        type ENUM('income', 'expense') NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(userid) ON DELETE SET NULL,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
    )");

    $stmt = $pdo->prepare("SELECT id FROM panel_admins WHERE username = ?");
    $stmt->execute(['admin']);
    if (!$stmt->fetch()) {
        $initial_admin_password = getenv('PANEL_ADMIN_PASSWORD') ?: '';
        if ($initial_admin_password === '') {
            die('PANEL_ADMIN_PASSWORD must be set before first run.');
        }
        $hashed_password = password_hash($initial_admin_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO panel_admins (username, password) VALUES (?, ?)");
        $stmt->execute(['admin', $hashed_password]);
    }

} catch (PDOException $e) {
    error_log('Database initialization failed: ' . $e->getMessage());
    die('Database initialization failed. Check server logs for details.');
}
