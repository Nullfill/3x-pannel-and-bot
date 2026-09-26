<?php
// Central runtime configuration.
// Prefer environment variables in production so credentials are not committed to Git.

define('TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: 'bottoken');

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'user');
define('DB_PASS', getenv('DB_PASS') ?: 'password');
define('DB_NAME', getenv('DB_NAME') ?: 'dbname');
?>
