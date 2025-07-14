<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/utils/Logger.php';

$token = TOKEN;
$logger = Logger::getInstance();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

function connectToDatabase() {
    global $logger;
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            throw new Exception("خطا در اتصال به دیتابیس: " . $conn->connect_error);
        }
        $logger->info("Database connection established successfully");
        return $conn;
    } catch (Exception $e) {
        $logger->error("Database connection failed", ['error' => $e->getMessage()]);
        return null;
    }
}

function closeDatabaseConnection($conn) {
    global $logger;
    if ($conn) {
        $conn->close();
        $logger->info("Database connection closed");
    }
}

function logMessage($message) {
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/app.log';
    
    // Create logs directory if it doesn't exist
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    $logger->warning("Invalid update received");
    exit;
}

function validateInput($update) {
    if (!isset($update['message']) && !isset($update['callback_query'])) {
        return false;
    }
    return true;
}

if (!validateInput($update)) {
    $logger->warning("Invalid input format");
    exit;
}

$conn = connectToDatabase();
if (!$conn) {
    exit;
}

try {
    if (isset($update['message'])) {
        handleStartCommand($update['message'], $conn);
    } elseif (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query'], $conn);
    }
} catch (Exception $e) {
    $logger->error("Error in main handler", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} finally {
    closeDatabaseConnection($conn);
}

function deletePreviousUserState($conn, $user_id) {
    $stmt = $conn->prepare("DELETE FROM user_states WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
}

function handleStartCommand($message, $conn) {
    $chat_id = $message['chat']['id'];
    $text = isset($message['text']) ? $message['text'] : '';
    $user_id = $message['from']['id'];

    $stmt = $conn->prepare("SELECT state, temp_data FROM user_states WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['state'] === 'waiting_balance_amount') {
            require_once __DIR__ . '/modules/my_purchases.php';
            processBalanceAmount($message, $conn);
            return;
        } elseif ($row['state'] === 'waiting_receipt_image') {
            require_once __DIR__ . '/modules/my_purchases.php';
            processReceiptImage($message, $conn);
            return;
        } elseif ($row['state'] === 'waiting_service_name') {
            require_once __DIR__ . '/modules/buy_service.php';
            processServiceName($message, $conn, $row['temp_data']);
            return;
        } elseif ($row['state'] === 'waiting_config') {
            require_once __DIR__ . '/modules/search_service.php';
            processConfig($message, $conn);
            return;
        }
    }

    if ($text === '/start') {
        createUsersTableIfNotExists($conn);

        $username = isset($message['from']['username']) ? $message['from']['username'] : '';
        $name = isset($message['from']['first_name']) ? $message['from']['first_name'] : '';

        saveUserInfo($conn, $user_id, $username, $name);

        showMainMenu($chat_id, $conn);
    }
}

function createUsersTableIfNotExists($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        userid BIGINT(20) NOT NULL,
        username VARCHAR(255),
        name VARCHAR(255),
        configcount INT(6) DEFAULT 0,
        balance INT(6) DEFAULT 0,
        UNIQUE (userid)
    )";

    if ($conn->query($sql) === TRUE) {
        logMessage("جدول users ایجاد شد یا از قبل وجود داشت.");
    } else {
        logMessage("خطا در ایجاد جدول users: " . $conn->error);
    }

    // Create transactions table
    $sql = "CREATE TABLE IF NOT EXISTS transactions (
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT(20) NOT NULL,
        product_id INT(6),
        amount INT(6) NOT NULL,
        type VARCHAR(50) NOT NULL,
        description TEXT,
        created_at DATETIME NOT NULL,
        INDEX (user_id),
        INDEX (product_id),
        INDEX (created_at)
    )";

    if ($conn->query($sql) === TRUE) {
        logMessage("جدول transactions ایجاد شد یا از قبل وجود داشت.");
    } else {
        logMessage("خطا در ایجاد جدول transactions: " . $conn->error);
    }

    // Create wallet_recharge_requests table
    $sql = "CREATE TABLE IF NOT EXISTS wallet_recharge_requests (
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT(20) NOT NULL,
        amount INT(6) NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        receipt_image VARCHAR(255),
        created_at DATETIME NOT NULL,
        approved_at DATETIME,
        approved_by BIGINT(20),
        INDEX (user_id),
        INDEX (status),
        INDEX (created_at)
    )";

    if ($conn->query($sql) === TRUE) {
        logMessage("جدول wallet_recharge_requests ایجاد شد یا از قبل وجود داشت.");
    } else {
        logMessage("خطا در ایجاد جدول wallet_recharge_requests: " . $conn->error);
    }

    // Create processed_callbacks table
    $sql = "CREATE TABLE IF NOT EXISTS processed_callbacks (
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        callback_id VARCHAR(255) NOT NULL,
        processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (callback_id),
        INDEX (processed_at)
    )";

    if ($conn->query($sql) === TRUE) {
        logMessage("جدول processed_callbacks ایجاد شد یا از قبل وجود داشت.");
    } else {
        logMessage("خطا در ایجاد جدول processed_callbacks: " . $conn->error);
    }
}

function saveUserInfo($conn, $user_id, $username, $name) {
    $sql = "INSERT INTO users (userid, username, name) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE username = VALUES(username), name = VALUES(name)";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        logMessage("خطا در آماده‌سازی عبارت SQL: " . $conn->error);
        return;
    }

    $stmt->bind_param("iss", $user_id, $username, $name);
    if ($stmt->execute()) {
        logMessage("اطلاعات کاربر با موفقیت ذخیره شد.");
    } else {
        logMessage("خطا در ذخیره اطلاعات کاربر: " . $stmt->error);
    }

    $stmt->close();
}

function showMainMenu($chat_id, $conn, $message_id = null) {
    $stmt = $conn->prepare("SELECT balance FROM users WHERE userid = ?");
    $stmt->bind_param('i', $chat_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $balance = $user ? $user['balance'] : 0;

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'خرید سرویس', 'callback_data' => 'buy_service']
            ],
            [
                ['text' => 'خرید‌های من', 'callback_data' => 'my_purchases']
            ],
            [
                ['text' => 'جستجوی سرویس', 'callback_data' => 'search_service']
            ]
        ]
    ];

    $message = "منوی اصلی:\n به ربات ما خوش آمدید لطفا یکی از منو های زیر رو انتخاب کنید \n \n 💰 موجودی حساب شما : " . number_format($balance) . " تومان";

    if ($message_id) {
        editMessage($chat_id, $message_id, $message, $keyboard);
    } else {
        sendMessage($chat_id, $message, $keyboard);
    }
}

function handleCallbackQuery($callback_query, $conn) {
    $data = $callback_query['data'];
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];

    if ($data === 'cancel_service_name') {
        $user_id = $callback_query['from']['id'];
        
        deletePreviousUserState($conn, $user_id);
        
        $message = "❌ عملیات لغو شد.";
        editMessage($chat_id, $message_id, $message);
        showMainMenu($chat_id, $conn);
        return;
    }

    if (in_array($data, ['buy_service', 'my_purchases', 'search_service', 'back_to_main'])) {
        if ($data === 'back_to_main') {
            showMainMenu($chat_id, $conn);
        } elseif ($data === 'my_purchases') {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => 'کانفیگ‌های من', 'callback_data' => 'show_my_configs'],
                        ['text' => 'افزایش اعتبار حساب', 'callback_data' => 'increase_balance']
                    ],
                    [
                        ['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']
                    ]
                ]
            ];
            $message = "برای مشاهده کانفیگ‌های خریداری شده یا افزایش اعتبار حساب، روی دکمه‌های زیر کلیک کنید.";
            editMessage($chat_id, $message_id, $message, $keyboard);
        } else {
            require_once __DIR__ . "/modules/$data.php";
            handleModule($callback_query, $conn);
        }
    } elseif (strpos($data, 'server_') === 0) {
        require_once __DIR__ . "/modules/buy_service.php";
        handleServerSelection($callback_query, $conn);
    } elseif (strpos($data, 'product_') === 0) {
        require_once __DIR__ . "/modules/buy_service.php";
        handleProductSelection($callback_query, $conn);
    } elseif (strpos($data, 'buy_product_') === 0) {
        require_once __DIR__ . "/modules/buy_service.php";
        handleBuyProduct($callback_query, $conn);
    } elseif (strpos($data, 'config_page_') === 0) {
        require_once __DIR__ . "/modules/my_purchases.php";
        $page = intval(substr($data, 12)); // Extract page number from 'config_page_X'
        showMyConfigs($chat_id, $conn, $page, $message_id);
    } elseif (strpos($data, 'config_') === 0) {
        require_once __DIR__ . "/modules/my_purchases.php";
        handleConfigDetails($callback_query, $conn);
    } elseif (strpos($data, 'renew_config_') === 0) {
        require_once __DIR__ . "/modules/renew_config.php";
        handleModule($callback_query, $conn);
    } elseif (strpos($data, 'confirm_renew_') === 0) {
        require_once __DIR__ . "/modules/renew_config.php";
        handleConfirmation($chat_id, $message_id, $data, $conn, $callback_query);
    } elseif (strpos($data, 'cancel_renew_') === 0) {
        require_once __DIR__ . "/modules/renew_config.php";
        handleCancellation($chat_id, $message_id, $data);
    } elseif ($data === 'back_to_purchases') {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'کانفیگ‌های من', 'callback_data' => 'show_my_configs'],
                    ['text' => 'افزایش اعتبار حساب', 'callback_data' => 'increase_balance']
                ],
                [
                    ['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']
                ]
            ]
        ];
        $message = "برای مشاهده کانفیگ‌های خریداری شده یا افزایش اعتبار حساب، روی دکمه‌های زیر کلیک کنید.";
        editMessage($chat_id, $message_id, $message, $keyboard);
    } elseif ($data === 'show_my_configs') {
        require_once __DIR__ . "/modules/my_purchases.php";
        handleModule($callback_query, $conn);
    } elseif ($data === 'increase_balance') {
        require_once __DIR__ . "/modules/my_purchases.php";
        handleIncreaseBalance($callback_query, $conn);
    } elseif (strpos($data, 'admin_approve_balance_') === 0) {
        require_once __DIR__ . "/modules/my_purchases.php";
        handleAdminApproveBalance($callback_query, $conn);
    } elseif (strpos($data, 'admin_reject_balance_') === 0) {
        require_once __DIR__ . "/modules/my_purchases.php";
        handleAdminRejectBalance($callback_query, $conn);
    } elseif (strpos($data, 'delete_config') === 0) {
        require_once __DIR__ . "/modules/my_purchases.php";
        handleDeleteConfig($callback_query, $conn);
    } else {
        sendMessage($chat_id, "⚠️ دستور نامعتبر است.");
    }
}

function sendMessage($chat_id, $text, $keyboard = null) {
    global $token, $logger;
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];

    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }

    $retryCount = 3;
    while ($retryCount > 0) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot$token/sendMessage");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new Exception("CURL Error: " . $curl_error);
            }

            if ($http_code !== 200) {
                throw new Exception("HTTP Error: " . $http_code . ", Response: " . $response);
            }

            $result = json_decode($response, true);

            if (!isset($result['ok']) || $result['ok'] !== true) {
                throw new Exception("Telegram API Error: " . ($result['description'] ?? 'Unknown error'));
            }

            $logger->info("Message sent successfully", ['chat_id' => $chat_id]);
            return $response;

        } catch (Exception $e) {
            $retryCount--;
            if ($retryCount === 0) {
                $logger->error("Failed to send message", [
                    'chat_id' => $chat_id,
                    'error' => $e->getMessage()
                ]);
                return false;
            }
            sleep(1);
        }
    }
}

function editMessage($chat_id, $message_id, $text, $keyboard = null) {
    global $token, $conn, $logger;
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'parse_mode' => 'Markdown'
    ];

    // اضافه کردن متن به داده‌ها
    $data['text'] = $text;

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot$token/editMessageText");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("CURL Error: " . $curl_error);
        }

        if ($http_code !== 200) {
            $result = json_decode($response, true);
            // اگر پیام خطای عدم وجود متن در پیام داشتیم، از editMessageCaption استفاده کنیم
            if (isset($result['description']) && strpos($result['description'], 'no text in the message') !== false) {
                $logger->info("Trying to edit message caption instead", [
                    'chat_id' => $chat_id,
                    'message_id' => $message_id
                ]);
                
                // تنظیم caption به جای text
                $caption_data = $data;
                unset($caption_data['text']);
                $caption_data['caption'] = $text;
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot$token/editMessageCaption");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $caption_data);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                
                $caption_response = curl_exec($ch);
                $caption_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($caption_http_code === 200) {
                    $result = json_decode($caption_response, true);
                } else {
                    throw new Exception("HTTP Error: " . $caption_http_code . ", Response: " . $caption_response);
                }
            } else {
                throw new Exception("HTTP Error: " . $http_code . ", Response: " . $response);
            }
        } else {
            $result = json_decode($response, true);
        }

        if (!isset($result['ok']) || $result['ok'] !== true) {
            throw new Exception("Telegram API Error: " . ($result['description'] ?? 'Unknown error'));
        }

        try {
            $cleanup_stmt = $conn->prepare("DELETE FROM bot_messages WHERE chat_id = ? AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM bot_messages 
                    WHERE chat_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT 10
                ) tmp
            )");
            $cleanup_stmt->bind_param('ii', $chat_id, $chat_id);
            $cleanup_stmt->execute();

            $stmt = $conn->prepare("INSERT INTO bot_messages (chat_id, message_id, message_text) VALUES (?, ?, ?)");
            $stmt->bind_param('iis', $chat_id, $message_id, $text);
            $stmt->execute();

            $logger->info("Message edited and saved to database", [
                'chat_id' => $chat_id,
                'message_id' => $message_id
            ]);

        } catch (Exception $e) {
            $logger->error("Database error in editMessage", [
                'error' => $e->getMessage()
            ]);
        }

        return $result;

    } catch (Exception $e) {
        $logger->error("Error in editMessage", [
            'message' => $e->getMessage()
        ]);

        try {
            return sendMessage($chat_id, $text, $keyboard);
        } catch (Exception $retry_error) {
            $logger->error("Retry error in editMessage", [
                'error' => $retry_error->getMessage()
            ]);
            return false;
        }
    }
}