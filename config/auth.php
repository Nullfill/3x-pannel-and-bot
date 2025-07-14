<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header("Location: /bot/pages/login.php");
        exit();
    }
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && 
           hash_equals($_SESSION['csrf_token'], $token);
}

function getCSRFToken() {
    return $_SESSION['csrf_token'] ?? '';
}

function loginUser($user_id) {
    $_SESSION['user_id'] = $user_id;
    $_SESSION['logged_in'] = true;
}

function logoutUser() {
    session_destroy();
    header("Location: /bot/pages/login.php");
    exit();
}