<?php
require_once __DIR__ . '/../utils/Logger.php';

$logger = Logger::getInstance();

function writeLog($message) {
    global $logger;
    $logger->info($message);
}

function handleModule($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];
    $data = $callback_query['data'];
    $user_id = $callback_query['from']['id'];

    $logger->info("Handling renew config module", [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'data' => $data
    ]);

    writeLog("Starting renewal process for user_id: $user_id");
    writeLog("Callback data: $data");

    // Extract config email from callback data
    $config_email = preg_replace('/^(renew_config_|confirm_renew_|cancel_renew_)/', '', $data);
    writeLog("Extracted config email: $config_email");

    try {
        // Get config details including config_id
        writeLog("Fetching config details from database...");
        $stmt = $conn->prepare("SELECT uc.*, s.url, s.cookies, s.name as server_name 
                               FROM usersconfig uc 
                               JOIN servers s ON uc.server_id = s.id 
                               WHERE uc.email_config = ?");
        if (!$stmt) {
            writeLog("Error preparing config query: " . $conn->error);
            throw new Exception("خطا در آماده‌سازی کوئری دیتابیس");
        }

        $stmt->bind_param('s', $config_email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            writeLog("No config found for email: $config_email");
            throw new Exception("خطا در دریافت اطلاعات کانفیگ.");
        }

        $config = $result->fetch_assoc();
        writeLog("Config details retrieved: " . json_encode($config));
        
        $config_id = $config['config_id'];
        writeLog("Config ID: $config_id");

        // Get product details based on config_id
        writeLog("Fetching product details for config_id: $config_id");
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        if (!$stmt) {
            writeLog("Error preparing product query: " . $conn->error);
            throw new Exception("خطا در آماده‌سازی کوئری محصول");
        }

        $stmt->bind_param('i', $config_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            writeLog("No product found for config_id: $config_id");
            throw new Exception("خطا در دریافت اطلاعات محصول.");
        }

        $product = $result->fetch_assoc();
        writeLog("Product details retrieved: " . json_encode($product));

        // Create confirmation message with product details
        $message = "🔄 تمدید کانفیگ\n\n";
        $message .= "📛 نام کانفیگ: {$config['name_config']}\n";
        $message .= "📧 ایمیل: {$config['email_config']}\n";
        $message .= "🖥 سرور: {$config['server_name']}\n\n";
        $message .= "📦 اطلاعات محصول:\n";
        $message .= "💰 قیمت: " . number_format($product['price']) . " تومان\n";
        $message .= "💾 حجم: {$product['volume_gb']} گیگابایت\n";
        $message .= "⏳ مدت زمان: {$product['days_count']} روز\n\n";
        $message .= "آیا مایل به تمدید کانفیگ هستید؟";

        // Create confirmation keyboard
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید تمدید', 'callback_data' => 'confirm_renew_' . $config_email],
                    ['text' => '❌ لغو', 'callback_data' => 'cancel_renew_' . $config_email]
                ]
            ]
        ];

        writeLog("Sending confirmation message to user");
        editMessage($chat_id, $message_id, $message, $keyboard);

    } catch (Exception $e) {
        writeLog("Error in handleModule: " . $e->getMessage());
        writeLog("Stack trace: " . $e->getTraceAsString());
        
        $error_message = "❌ خطا در پردازش درخواست تمدید:\n" . $e->getMessage();
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به لیست کانفیگ‌ها', 'callback_data' => 'show_my_configs']]
            ]
        ];
        editMessage($chat_id, $message_id, $error_message, $keyboard);
    }
}

// Handle confirmation
function handleConfirmation($chat_id, $message_id, $data, $conn, $callback_query) {
    global $logger;
    $logger->info("Handling config renewal confirmation", [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'data' => $data
    ]);
    
    // Check if this callback has already been processed
    $callback_id = $callback_query['id'];
    $stmt = $conn->prepare("SELECT id FROM processed_callbacks WHERE callback_id = ?");
    if (!$stmt) {
        writeLog("Error preparing callback check query: " . $conn->error);
        return;
    }
    
    $stmt->bind_param('s', $callback_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        writeLog("Callback already processed, skipping: $callback_id");
        return;
    }
    
    // Mark this callback as processed
    $stmt = $conn->prepare("INSERT INTO processed_callbacks (callback_id) VALUES (?)");
    if (!$stmt) {
        writeLog("Error preparing callback insert query: " . $conn->error);
        return;
    }
    
    $stmt->bind_param('s', $callback_id);
    $stmt->execute();
    
    // Extract config email from callback data
    $config_email = preg_replace('/^confirm_renew_/', '', $data);
    writeLog("Config email from confirmation: $config_email");
    
    try {
        // Get config and product details
        writeLog("Fetching config and product details for confirmation");
        $stmt = $conn->prepare("SELECT uc.*, s.url, s.cookies, s.name as server_name, p.*
                               FROM usersconfig uc 
                               JOIN servers s ON uc.server_id = s.id
                               JOIN products p ON uc.config_id = p.id
                               WHERE uc.email_config = ?");
        if (!$stmt) {
            writeLog("Error preparing confirmation query: " . $conn->error);
            throw new Exception("خطا در آماده‌سازی کوئری تایید");
        }

        $stmt->bind_param('s', $config_email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            writeLog("No config found for confirmation email: $config_email");
            throw new Exception("خطا در دریافت اطلاعات کانفیگ.");
        }

        $config = $result->fetch_assoc();
        writeLog("Config details for confirmation: " . json_encode($config));
        
        $user_id = $config['userid_c'];
        writeLog("User ID for confirmation: $user_id");

        // Check user balance
        writeLog("Checking user balance");
        $stmt = $conn->prepare("SELECT balance FROM users WHERE userid = ?");
        if (!$stmt) {
            writeLog("Error preparing balance query: " . $conn->error);
            throw new Exception("خطا در آماده‌سازی کوئری موجودی");
        }

        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        writeLog("User balance: " . ($user ? $user['balance'] : 'not found'));

        if (!$user || $user['balance'] < $config['price']) {
            writeLog("Insufficient balance. Required: {$config['price']}, Available: " . ($user ? $user['balance'] : 0));
            throw new Exception("موجودی کیف پول شما کافی نیست. لطفاً حساب خود را شارژ کنید.");
        }

        // Get current config details
        $panel_url = rtrim($config['url'], '/') . '/panel/inbound/list';
        
        // Get current config details
        $ch = curl_init();
        $target_url = 'https://jorabin.ir/bot/pages/tel/modules/getdatapanel.php';
        $postData = http_build_query([
            'url' => $panel_url,
            'email' => $config_email,
            'cookie' => $config['cookies']
        ]);
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $target_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            writeLog("Error connecting to server. HTTP code: $http_code");
            throw new Exception("خطا در ارتباط با سرور");
        }

        $data = json_decode($response, true);
        if (!$data['success']) {
            writeLog("Error fetching config data: " . json_encode($data));
            throw new Exception($data['msg'] ?? "خطا در دریافت اطلاعات کانفیگ");
        }

        // Get config_id from usersconfig
        $config_id = $config['config_id'];
        writeLog("Config ID from usersconfig: $config_id");

        // Get config_ids from products table
        $stmt = $conn->prepare("SELECT config_ids FROM products WHERE id = ?");
        if (!$stmt) {
            writeLog("Error preparing product query: " . $conn->error);
            throw new Exception("خطا در آماده‌سازی کوئری محصول");
        }

        $stmt->bind_param('i', $config_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();
        
        if (!$product) {
            writeLog("No product found for config_id: $config_id");
            throw new Exception("خطا در دریافت اطلاعات محصول");
        }

        $config_ids = $product['config_ids'];
        writeLog("Config IDs from product: $config_ids");

        // Get config_settings from configs table
        $stmt = $conn->prepare("SELECT config_settings FROM configs WHERE id = ?");
        if (!$stmt) {
            writeLog("Error preparing configs query: " . $conn->error);
            throw new Exception("خطا در آماده‌سازی کوئری کانفیگ‌ها");
        }

        $stmt->bind_param('i', $config_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $config_data = $result->fetch_assoc();
        
        if (!$config_data) {
            writeLog("No config found for config_ids: $config_ids");
            throw new Exception("خطا در دریافت تنظیمات کانفیگ");
        }

        writeLog("Config settings: " . $config_data['config_settings']);

        // Parse config_settings to get inbound ID
        parse_str($config_data['config_settings'], $settings);
        $inbound_id = $settings['id'];
        
        if (!$inbound_id) {
            writeLog("Could not find inbound ID in config settings");
            throw new Exception("خطا در پیدا کردن شناسه کانفیگ در تنظیمات");
        }

        writeLog("Found Inbound ID: $inbound_id");

        // Reset traffic for the config
        writeLog("Resetting traffic for config");
        $reset_url = rtrim($config['url'], '/') . "/panel/inbound/$inbound_id/resetClientTraffic/$config_email";
        
        writeLog("Reset URL: $reset_url");
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $reset_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => "",
            CURLOPT_HTTPHEADER => [
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:136.0) Gecko/20100101 Firefox/136.0",
                "Accept: application/json, text/plain, */*",
                "Accept-Language: en-US,en;q=0.5",
                "Accept-Encoding: gzip, deflate",
                "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
                "X-Requested-With: XMLHttpRequest",
                "Origin: " . rtrim($config['url'], '/'),
                "Connection: keep-alive",
                "Referer: " . rtrim($config['url'], '/') . "/panel/inbounds",
                "Cookie: " . $config['cookies'],
                "Priority: u=0"
            ],
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $reset_response = curl_exec($ch);
        $reset_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        writeLog("Reset response: $reset_response");
        writeLog("Reset HTTP code: $reset_http_code");
        if ($curl_error) {
            writeLog("Curl error: $curl_error");
        }

        if ($reset_http_code !== 200) {
            writeLog("Error resetting traffic. HTTP code: $reset_http_code");
            throw new Exception("خطا در ریست ترافیک کانفیگ");
        }

        // Check if response is empty or invalid
        if (empty($reset_response)) {
            writeLog("Empty response from server");
            throw new Exception("خطا در دریافت پاسخ از سرور");
        }

        // Try to decode response as JSON
        $reset_data = json_decode($reset_response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            writeLog("Invalid JSON response: " . json_last_error_msg());
            writeLog("Raw response: " . $reset_response);
            throw new Exception("خطا در پردازش پاسخ سرور");
        }

        if (!$reset_data['success']) {
            writeLog("Error resetting traffic. Response: " . $reset_response);
            throw new Exception("خطا در ریست ترافیک کانفیگ");
        }

        writeLog("Traffic reset successful");

        // Extract UUID from config_c
        $config_c = $config['config_c'];
        writeLog("Config C: $config_c");
        
        // Extract UUID from config_c
        $uuid = null;
        
        // Try to extract from VLESS link
        if (preg_match('/vless:\/\/([^@]+)@/', $config_c, $matches)) {
            $uuid = $matches[1];
            writeLog("Extracted UUID from VLESS: $uuid");
        } 
        // Try to extract from VMESS link
        else if (preg_match('/vmess:\/\/(.+)/', $config_c, $matches)) {
            $base64 = $matches[1];
            $json = base64_decode($base64);
            if ($json) {
                $data = json_decode($json, true);
                if (isset($data['id'])) {
                    $uuid = $data['id'];
                    writeLog("Extracted UUID from VMESS: $uuid");
                }
            }
        }
        
        if (!$uuid) {
            writeLog("Failed to extract UUID from config_c");
            throw new Exception("خطا در استخراج UUID از کانفیگ");
        }

        // Calculate expiry time (current timestamp + days in milliseconds)
        $days = $config['days_count'];
        $expiry_time = time() * 1000 + ($days * 24 * 60 * 60 * 1000);
        writeLog("Calculated expiry time: $expiry_time");

        // Calculate total GB in bytes
        $volume_gb = $config['volume_gb'];
        $total_gb = $volume_gb * 1024 * 1024 * 1024;
        writeLog("Calculated total GB in bytes: $total_gb");

        // Construct update URL
        $update_url = rtrim($config['url'], '/') . "/panel/inbound/updateClient/$uuid";
        writeLog("Update URL: $update_url");

        // Prepare POST data
        $post_data = [
            'id' => $inbound_id,
            'settings' => json_encode([
                'clients' => [
                    [
                        'id' => $uuid,
                        'flow' => '',
                        'email' => $config_email,
                        'limitIp' => 0,
                        'totalGB' => $total_gb,
                        'expiryTime' => $expiry_time,
                        'enable' => true,
                        'tgId' => '',
                        'subId' => '',
                        'comment' => '',
                        'reset' => 0
                    ]
                ]
            ])
        ];

        // Send update request
        writeLog("Sending update request");
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $update_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post_data),
            CURLOPT_HTTPHEADER => [
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:136.0) Gecko/20100101 Firefox/136.0",
                "Accept: application/json, text/plain, */*",
                "Accept-Language: en-US,en;q=0.5",
                "Accept-Encoding: gzip, deflate",
                "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
                "X-Requested-With: XMLHttpRequest",
                "Origin: " . rtrim($config['url'], '/'),
                "Connection: keep-alive",
                "Referer: " . rtrim($config['url'], '/') . "/panel/inbounds",
                "Cookie: " . $config['cookies'],
                "Priority: u=0"
            ],
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $update_response = curl_exec($ch);
        $update_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        writeLog("Update response: $update_response");
        writeLog("Update HTTP code: $update_http_code");
        if ($curl_error) {
            writeLog("Curl error: $curl_error");
        }

        if ($update_http_code !== 200) {
            writeLog("Error updating client. HTTP code: $update_http_code");
            throw new Exception("خطا در بروزرسانی تنظیمات کانفیگ");
        }

        // Check if response is empty or invalid
        if (empty($update_response)) {
            writeLog("Empty response from server for update request");
            throw new Exception("خطا در دریافت پاسخ از سرور");
        }

        // Try to decode response as JSON
        $update_data = json_decode($update_response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            writeLog("Invalid JSON response: " . json_last_error_msg());
            writeLog("Raw response: " . $update_response);
            throw new Exception("خطا در پردازش پاسخ سرور");
        }

        if (!$update_data['success']) {
            writeLog("Error updating client. Response: " . $update_response);
            throw new Exception("خطا در بروزرسانی تنظیمات کانفیگ");
        }

        writeLog("Client update successful");

        // Deduct balance
        $new_balance = $user['balance'] - $config['price'];
        $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE userid = ?");
        $stmt->bind_param('ii', $new_balance, $user_id);
        $stmt->execute();

        // Record transaction with negative amount for renewal
        $transaction_type = 'renewal';
        $transaction_description = "تمدید کانفیگ {$config['name_config']} ({$config['email_config']})";
        $negative_amount = -$config['price']; // Make the amount negative
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('iiss', $user_id, $negative_amount, $transaction_type, $transaction_description);
        $stmt->execute();

        // Send success message
        $message = "✅ کانفیگ شما با موفقیت تمدید شد.\n\n";
        $message .= "📛 نام کانفیگ: {$config['name_config']}\n";
        $message .= "📧 ایمیل: {$config['email_config']}\n";
        $message .= "💰 مبلغ پرداختی: " . number_format($config['price']) . " تومان\n";
        $message .= "💾 حجم جدید: {$config['volume_gb']} گیگابایت\n";
        $message .= "⏳ مدت زمان: {$config['days_count']} روز";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به لیست کانفیگ‌ها', 'callback_data' => 'show_my_configs']]
            ]
        ];

        // Edit the message first
        editMessage($chat_id, $message_id, $message, $keyboard);
        
        // Then answer the callback query
        $callback_data = [
            'callback_query_id' => $callback_query['id'],
            'text' => "✅ کانفیگ با موفقیت تمدید شد"
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($callback_data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        curl_exec($ch);
        curl_close($ch);

    } catch (Exception $e) {
        writeLog("Error in confirm_renew: " . $e->getMessage());
        writeLog("Stack trace: " . $e->getTraceAsString());
        
        $error_message = "❌ خطا در تمدید کانفیگ:\n" . $e->getMessage();
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به لیست کانفیگ‌ها', 'callback_data' => 'show_my_configs']]
            ]
        ];
        
        // Edit the message first
        editMessage($chat_id, $message_id, $error_message, $keyboard);
        
        // Then answer the callback query with error
        $callback_data = [
            'callback_query_id' => $callback_query['id'],
            'text' => "❌ خطا در تمدید کانفیگ"
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($callback_data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

// Handle cancellation
function handleCancellation($chat_id, $message_id, $data) {
    global $logger;
    $logger->info("Handling config renewal cancellation", [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'data' => $data
    ]);

    writeLog("Processing cancellation");
    // Extract config email from callback data
    $config_email = preg_replace('/^cancel_renew_/', '', $data);
    writeLog("Cancelling renewal for config email: $config_email");
    
    $message = "❌ عملیات تمدید کانفیگ لغو شد.";
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🔙 بازگشت به لیست کانفیگ‌ها', 'callback_data' => 'show_my_configs']]
        ]
    ];
    
    editMessage($chat_id, $message_id, $message, $keyboard);
}

// Helper function to convert GB to bytes
function convertGBToBytes($gb) {
    global $logger;
    $bytes = $gb * 1024 * 1024 * 1024;
    $logger->debug("Converting GB to bytes", [
        'gb' => $gb,
        'bytes' => $bytes
    ]);
    return $bytes;
}
?>