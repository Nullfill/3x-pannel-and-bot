<?php
function getDBConnection() {
    // Database configuration
    $host = 'localhost';
    $username = 'jorabini_user';
    $password = 'Hamed@141512';
    $database = 'jorabini_3xui';

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