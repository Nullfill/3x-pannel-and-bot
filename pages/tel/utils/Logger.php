<?php

class Logger {
    private static $instance = null;
    private $logFile;
    private $logDir;

    private function __construct() {
        $this->logDir = __DIR__ . '/../logs';
        $this->logFile = $this->logDir . '/app.log';
        
        // Create logs directory if it doesn't exist
        if (!file_exists($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function writeLog($level, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    public function info($message, $context = []) {
        $this->writeLog('INFO', $message, $context);
    }

    public function error($message, $context = []) {
        $this->writeLog('ERROR', $message, $context);
    }

    public function debug($message, $context = []) {
        $this->writeLog('DEBUG', $message, $context);
    }

    public function warning($message, $context = []) {
        $this->writeLog('WARNING', $message, $context);
    }

    public function critical($message, $context = []) {
        $this->writeLog('CRITICAL', $message, $context);
    }
} 