<?php
require_once __DIR__ . '/../../config.php';

function getDBConnection() {
    // Reuse the central database configuration instead of storing credentials here.
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        error_log("Database connection failed.");
        return null;
    }

    $conn->set_charset("utf8mb4");

    return $conn;
}
