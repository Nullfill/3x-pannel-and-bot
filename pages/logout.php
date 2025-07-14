<?php
require_once '../config/auth.php';

// خروج کاربر
logoutUser();

// ریدایرکت به صفحه لاگین
header("Location: /bot/pages/login.php");
exit; 