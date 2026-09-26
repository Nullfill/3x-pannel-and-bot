<?php
// Central runtime configuration.
// Keep secrets out of Git: set these values through environment variables.

define('TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: '');
?>
