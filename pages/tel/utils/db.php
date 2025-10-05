<?php
function getDBConnection() {
    // Database configuration - استفاده از فایل config برای اطلاعات دیتابیس
    // توجه: اطلاعات زیر را از فایل config.php خوانده و به صورت hardcode ننویسید
    require_once __DIR__ . '/../../config.php';
    
    $host = DB_HOST;
    $username = DB_USER;
    $password = DB_PASS;
    $database = DB_NAME;

    // Create connection
    $conn = new mysqli($host, $username, $password, $database);

    // Check connection
    if ($conn->connect_error) {
        error_log("Connection failed: " . $conn->connect_error);
        return null;
    }

    // Set charset to utf8
    $conn->set_charset("utf8");

    return $conn;
} 