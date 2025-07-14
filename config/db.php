<?php
$db_host = 'localhost';
$db_name = 'jorabini_3xui';
$db_user = 'jorabini_user';
$db_pass = 'Hamed@141512';

try {
    $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $db_name");
    $pdo->exec("USE $db_name");
    
    // Create panel_admins table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS panel_admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create users table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        userid INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create products table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        price DECIMAL(10,2) NOT NULL,
        volume_gb INT NOT NULL,
        days_count INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create transactions table if not exists
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
    
    // Check if admin user exists, if not create it
    $stmt = $pdo->prepare("SELECT id FROM panel_admins WHERE username = ?");
    $stmt->execute(['admin']);
    if (!$stmt->fetch()) {
        $hashed_password = password_hash('141512', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO panel_admins (username, password) VALUES (?, ?)");
        $stmt->execute(['admin', $hashed_password]);
    }
    
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
// End of db.php