<?php
require_once __DIR__ . '/../utils/Logger.php';

$logger = Logger::getInstance();

function handleModule($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];
    $user_id = $callback_query['from']['id'];

    $logger->info("Handling my purchases module", [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);

    // حذف state قبلی
    deletePreviousUserState($conn, $user_id);
    
    // به جای نمایش مستقیم کانفیگ‌ها، از تابع showMyConfigs استفاده می‌کنیم
    // که قبلاً برای صفحه‌بندی طراحی شده است
    showMyConfigs($chat_id, $conn, 1, $message_id);
}

function handleIncreaseBalance($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];
    $user_id = $callback_query['from']['id'];

    $logger->info("Handling increase balance request", [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);

    // حذف state قبلی
    deletePreviousUserState($conn, $user_id);

    $message = "💳 برای افزایش اعتبار حساب، لطفاً مبلغ مورد نظر را به تومان وارد کنید (مثال: 50000):";
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']]
        ]
    ];

    editMessage($chat_id, $message_id, $message, $keyboard);

    $stmt = $conn->prepare("INSERT INTO user_states (user_id, state, temp_data) VALUES (?, ?, ?)");
    $state = 'waiting_balance_amount';
    $temp_data = json_encode([]);
    $stmt->bind_param('iss', $user_id, $state, $temp_data);
    $stmt->execute();
}

function processBalanceAmount($message, $conn) {
    global $logger;
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $text = $message['text'];

    $logger->info("Processing balance amount", [
        'chat_id' => $chat_id,
        'text' => $text
    ]);

    if (!is_numeric($text)) {
        sendMessage($chat_id, "❌ مبلغ وارد شده نامعتبر است. لطفاً یک عدد وارد کنید (مثال: 50000).");
        return;
    }

    $amount = intval($text);

    // حذف state قبلی
    deletePreviousUserState($conn, $user_id);

    $stmt = $conn->prepare("INSERT INTO user_states (user_id, state, temp_data) VALUES (?, ?, ?)");
    $state = 'waiting_receipt_image';
    $temp_data = json_encode(['amount' => $amount]);
    $stmt->bind_param('iss', $user_id, $state, $temp_data);
    $stmt->execute();

    $message = "💳 لطفاً مبلغ $amount تومان را به شماره کارت زیر واریز کنید:\n\n";
    $message .= "شماره کارت: 2072-0067-7210-5041\n\n";
    $message .= "پس از واریز، لطفاً عکس رسید را همینجا ارسال کنید.";

    sendMessage($chat_id, $message);
}

function processReceiptImage($message, $conn) {
    global $logger;
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    
    // Check if photo exists and get the best quality version
    if (!isset($message['photo']) || !is_array($message['photo'])) {
        sendMessage($chat_id, "❌ لطفا یک تصویر معتبر ارسال کنید.");
        return;
    }
    
    // Get the last (highest quality) photo
    $photo = end($message['photo']);
    $file_id = $photo['file_id'];
    
    if (!$file_id) {
        sendMessage($chat_id, "❌ خطا در دریافت تصویر. لطفا مجددا تلاش کنید.");
        return;
    }

    try {
        // Get user's pending amount
        $stmt = $conn->prepare("SELECT temp_data FROM user_states WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if (!$row) {
            sendMessage($chat_id, "❌ خطا: اطلاعات درخواست یافت نشد.");
            return;
        }

        $temp_data = json_decode($row['temp_data'], true);
        $amount = intval($temp_data['amount']);

        $logger->info("Processing receipt image", [
            'user_id' => $user_id,
            'amount' => $amount,
            'file_id' => $file_id
        ]);

        // Get file path from Telegram
        $file_info = getFileInfo($file_id);
        if (!$file_info) {
            throw new Exception("خطا در دریافت اطلاعات فایل");
        }

        // Download and save the image
        $image_url = "https://api.telegram.org/file/bot" . $GLOBALS['token'] . "/" . $file_info['file_path'];
        $image_data = file_get_contents($image_url);
        
        if (!$image_data) {
            throw new Exception("خطا در دانلود تصویر");
        }

        // Create uploads directory if it doesn't exist
        $upload_dir = __DIR__ . "/../../uploads/receipts/";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $filename = uniqid() . '_' . $file_id . '.jpg';
        $file_path = $upload_dir . $filename;
        
        // Save the file
        file_put_contents($file_path, $image_data);
        
        // Update the relative path to match the actual file location
        $relative_path = "pages/uploads/receipts/" . $filename;
        
        // دریافت اطلاعات کاربر
        $user_stmt = $conn->prepare("SELECT username, name FROM users WHERE userid = ?");
        $user_stmt->bind_param('i', $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_info = $user_result->fetch_assoc();
        
        $user_name = $user_info ? $user_info['name'] : 'بدون نام';
        $user_username = $user_info ? $user_info['username'] : '';
        
        // اصلاح نام کاربری برای مارک‌داون تلگرام
        $escaped_username = $user_username ? str_replace('_', '\_', $user_username) : '';
        
        // Save the recharge request in wallet_recharge_requests table with the file path
        $stmt = $conn->prepare("INSERT INTO wallet_recharge_requests (user_id, amount, receipt_image, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param('iis', $user_id, $amount, $relative_path);
        if (!$stmt->execute()) {
            $logger->error("Failed to insert wallet_recharge_request", [
                'error' => $stmt->error
            ]);
            throw new Exception("خطا در ثبت درخواست افزایش موجودی: " . $stmt->error);
        }

        $request_id = $conn->insert_id;
        $logger->info("Wallet recharge request created", [
            'request_id' => $request_id
        ]);

        // Get admin list - Modified to get all admins
        $stmt = $conn->prepare("SELECT userid FROM admins");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $admin_count = 0;
        while ($admin = $result->fetch_assoc()) {
            $admin_id = $admin['userid'];
            
            // ساختن دکمه‌های تایید و رد با فرمت درست - مطمئن شویم که فرمت با توابع handleAdmin* مطابقت دارد
            $approve_callback = "admin_approve_balance_{$request_id}";
            $reject_callback = "admin_reject_balance_{$request_id}";
            
            $logger->info("Preparing callback data", [
                'approve_callback' => $approve_callback,
                'reject_callback' => $reject_callback,
                'user_id' => $user_id,
                'admin_id' => $admin_id,
                'amount' => $amount,
                'request_id' => $request_id
            ]);
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ تأیید', 'callback_data' => $approve_callback],
                        ['text' => '❌ رد', 'callback_data' => $reject_callback]
                    ]
                ]
            ];
                
            // ایجاد پیام مارکداون برای ادمین با اطلاعات دقیق‌تر
            $admin_message = "📤 *درخواست افزایش اعتبار جدید*\n\n" .
                           "👤 کاربر: [$user_name](tg://user?id=$user_id)\n" .
                           "🆔 شناسه: `$user_id`\n" .
                           ($user_username ? "👤 یوزرنیم: @$escaped_username\n" : "") .
                           "💰 مبلغ: " . number_format($amount) . " تومان\n" .
                           "⏱ زمان: " . date('Y-m-d H:i:s') . "\n\n" .
                           "📝 شناسه درخواست: `$request_id`";
            
            $send_result = sendPhoto($admin_id, $file_id, $admin_message, $keyboard);
            
            if ($send_result) {
                $admin_count++;
                $logger->info("Notification sent to admin", [
                    'admin_id' => $admin_id
                ]);
            } else {
                $logger->error("Failed to send notification to admin", [
                    'admin_id' => $admin_id
                ]);
            }
        }

        if ($admin_count > 0) {
            // Update state to waiting for admin approval
            $stmt = $conn->prepare("UPDATE user_states SET state = ? WHERE user_id = ?");
            $new_state = 'waiting_admin_approval';
            $stmt->bind_param('si', $new_state, $user_id);
            $stmt->execute();
            
            sendMessage($chat_id, "✅ عکس رسید شما به ادمین‌ها ارسال شد. منتظر تأیید باشید.");
            
            // ارسال پیام به کانال لاگ
            $log_message = "📤 *درخواست افزایش موجودی جدید*\n\n" .
                         "👤 کاربر: [$user_name](tg://user?id=$user_id)\n" .
                         "🆔 آیدی: `$user_id`\n" .
                         ($user_username ? "👤 یوزرنیم: @$escaped_username\n" : "") .
                         "💰 مبلغ: " . number_format($amount) . " تومان\n" .
                         "⏱ زمان: " . date('Y-m-d H:i:s') . "\n" .
                         "📝 وضعیت: در انتظار تایید";
            sendToLogChannel($log_message);
        } else {
            sendMessage($chat_id, "⚠️ خطا در ارسال عکس به ادمین‌ها. لطفا با پشتیبانی تماس بگیرید.");
        }

    } catch (Exception $e) {
        $logger->error("Error in processReceiptImage", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        sendMessage($chat_id, "❌ خطا در پردازش درخواست. لطفا مجددا تلاش کنید.");
    }
}

function getFileInfo($file_id) {
    global $logger;
    $logger->info("Getting file info", [
        'file_id' => $file_id
    ]);

    global $token;
    $url = "https://api.telegram.org/bot$token/getFile?file_id=$file_id";
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    
    if ($data && isset($data['ok']) && $data['ok']) {
        return $data['result'];
    }
    return false;
}

function sendPhoto($chat_id, $photo, $caption, $keyboard = null) {
    global $logger, $token;
    $logger->info("Sending photo", [
        'chat_id' => $chat_id,
        'caption_length' => strlen($caption),
        'photo_type' => is_string($photo) ? (strpos($photo, 'http') === 0 ? 'URL' : 'FILE_ID') : 'FILE'
    ]);

    // تنظیم داده‌ها براساس نوع عکس (URL یا FILE)
    if (is_string($photo) && strpos($photo, 'http') === 0) {
        // اگر URL است
        $data = [
            'chat_id' => $chat_id,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'Markdown'
        ];
    } else if (is_string($photo) && file_exists($photo)) {
        // اگر مسیر فایل محلی است
        $data = [
            'chat_id' => $chat_id,
            'photo' => new CURLFile($photo),
            'caption' => $caption,
            'parse_mode' => 'Markdown'
        ];
    } else {
        // اگر فایل آپلود یا file_id است
        $data = [
            'chat_id' => $chat_id,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'Markdown'
        ];
    }
    
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }

    $retryCount = 3;
    while ($retryCount > 0) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot$token/sendPhoto");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($response === false) {
                throw new Exception("CURL Error: " . $error . " (Code: " . $errno . ")");
            }

            if ($http_code !== 200) {
                $errorData = json_decode($response, true);
                $errorMsg = isset($errorData['description']) ? $errorData['description'] : 'Unknown HTTP error';
                throw new Exception("HTTP Error: " . $http_code . ", Response: " . $errorMsg);
            }

            $result = json_decode($response, true);
            if (!isset($result['ok']) || $result['ok'] !== true) {
                throw new Exception("Telegram API Error: " . ($result['description'] ?? 'Unknown error'));
            }

            $logger->info("Photo sent successfully", [
                'chat_id' => $chat_id,
                'message_id' => $result['result']['message_id'] ?? 'unknown'
            ]);
            return true;

        } catch (Exception $e) {
            $retryCount--;
            $logger->error("Error sending photo (attempt " . (3 - $retryCount) . "/3)", [
                'error' => $e->getMessage(),
                'chat_id' => $chat_id
            ]);
            
            if ($retryCount === 0) {
                $logger->error("Failed to send photo after 3 attempts");
                return false;
            }
            
            sleep(1); // Wait 1 second before retry
        }
    }
    
    return false;
}

/**
 * Send log message to log channel
 */
function sendToLogChannel($message) {
    global $token, $logger;
    $log_channel_id = "-1001617877546"; // آیدی عددی گروه لاگ
    
    $logger->info("Sending log to channel", [
        'channel_id' => $log_channel_id,
        'message_length' => strlen($message)
    ]);
    
    $data = [
        'chat_id' => $log_channel_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];

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
            $logger->error("CURL Error in sendToLogChannel: " . $curl_error, [
                'error_code' => curl_errno($ch)
            ]);
            return false;
        }

        if ($http_code !== 200) {
            $logger->error("HTTP Error in sendToLogChannel: " . $http_code . ", Response: " . $response, [
                'data' => json_encode($data)
            ]);
            return false;
        }

        $result = json_decode($response, true);

        if (!isset($result['ok']) || $result['ok'] !== true) {
            $logger->error("Telegram API Error in sendToLogChannel: " . ($result['description'] ?? 'Unknown error'), [
                'result' => json_encode($result)
            ]);
            return false;
        }

        $logger->info("Log message sent to channel successfully");
        return true;

    } catch (Exception $e) {
        $logger->error("Exception in sendToLogChannel: " . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        return false;
    }
}

function handleAdminApproveBalance($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];
    $data = $callback_query['data'];
    $admin_id = $callback_query['from']['id'];
    $admin_username = $callback_query['from']['username'] ?? 'بدون نام کاربری';
    
    // لاگ کامل اطلاعات کالبک کوئری برای اشکال زدایی
    $logger->info("Callback query full data", [
        'callback_query' => json_encode($callback_query)
    ]);
    
    $logger->info("Handling admin balance approval", [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'callback_data' => $data,
        'admin_id' => $admin_id,
        'admin_username' => $admin_username
    ]);
    
    // استخراج اطلاعات از callback_data با دقت بیشتر
    $data_parts = explode('_', $data);
    $logger->info("Data parts", ['parts' => json_encode($data_parts), 'count' => count($data_parts)]);
    
    if (count($data_parts) < 4) {
        $logger->error("Invalid callback data format", ['data' => $data, 'parts_count' => count($data_parts)]);
        editMessage($chat_id, $message_id, "⚠️ فرمت داده‌ها نامعتبر است. تعداد بخش‌ها: " . count($data_parts));
        return;
    }
    
    // استخراج شناسه درخواست از callback_data
    $request_id = intval($data_parts[3]);
    
    if ($request_id <= 0) {
        $logger->error("Invalid request_id", ['request_id' => $request_id, 'data' => $data]);
        editMessage($chat_id, $message_id, "⚠️ شناسه درخواست نامعتبر است: $request_id");
        return;
    }
    
    $logger->info("Extracted request_id", [
        'request_id' => $request_id
    ]);

    try {
        // Start transaction
        $conn->begin_transaction();
        
        // دریافت اطلاعات درخواست از طریق شناسه درخواست
        $query = "SELECT id, user_id, status, amount FROM wallet_recharge_requests WHERE id = ? AND status = 'pending'";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $logger->info("Request query", [
            'query' => $query,
            'request_id' => $request_id,
            'result_count' => $result->num_rows
        ]);
        
        if ($result->num_rows === 0) {
            // No pending request found
            $logger->error("No pending request found", ['request_id' => $request_id]);
            editMessage($chat_id, $message_id, "⚠️ درخواست در حال انتظاری با شناسه $request_id یافت نشد.");
            return;
        }
        
        // دریافت اطلاعات درخواست
        $request = $result->fetch_assoc();
        $user_id = $request['user_id'];
        $amount = $request['amount']; // استفاده از مقدار دقیق ذخیره شده در دیتابیس
        
        $logger->info("Request details", [
            'request_id' => $request_id,
            'user_id' => $user_id,
            'amount' => $amount
        ]);
        
        // Get user information
        $stmt = $conn->prepare("SELECT name, username FROM users WHERE userid = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_info = $result->fetch_assoc();
        
        if (!$user_info) {
            $logger->error("User info not found", ['user_id' => $user_id]);
            throw new Exception("اطلاعات کاربر یافت نشد");
        }
        
        $user_name = $user_info['name'] ?? 'بدون نام';
        $user_username = $user_info['username'] ?? 'بدون نام کاربری';

        // اصلاح نام کاربری برای مارک‌داون تلگرام
        $escaped_username = str_replace('_', '\_', $user_username);

        // Update user balance
        $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE userid = ?");
        $stmt->bind_param('ii', $amount, $user_id);
        if (!$stmt->execute()) {
            $logger->error("Failed to update balance", [
                'error' => $stmt->error,
                'user_id' => $user_id,
                'amount' => $amount
            ]);
            throw new Exception("Failed to update balance: " . $stmt->error);
        }
        
        $affected_rows = $stmt->affected_rows;
        if ($affected_rows <= 0) {
            $logger->warning("No rows affected when updating balance", [
                'user_id' => $user_id,
                'amount' => $amount
            ]);
        }

        // Update wallet_recharge_requests status
        $stmt = $conn->prepare("UPDATE wallet_recharge_requests SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $admin_id, $request_id);
        if (!$stmt->execute()) {
            $logger->error("Failed to update request status", [
                'error' => $stmt->error
            ]);
            throw new Exception("Failed to update recharge request status: " . $stmt->error);
        }

        // Add transaction record
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, ?, ?, NOW())");
        $type = 'wallet_recharge';
        $description = 'افزایش موجودی کیف پول';
        $stmt->bind_param('iiss', $user_id, $amount, $type, $description);
        if (!$stmt->execute()) {
            $logger->error("Failed to add transaction record", [
                'error' => $stmt->error
            ]);
            throw new Exception("Failed to add transaction record: " . $stmt->error);
        }

        // Delete user state
        $stmt = $conn->prepare("DELETE FROM user_states WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        if (!$stmt->execute()) {
            $logger->error("Failed to delete user state", [
                'error' => $stmt->error
            ]);
            throw new Exception("Failed to delete user state: " . $stmt->error);
        }

        $conn->commit();
        $logger->info("Balance recharge approved successfully", [
            'user_id' => $user_id,
            'amount' => $amount
        ]);

        // Get all admins
        $stmt = $conn->prepare("SELECT userid FROM admins");
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Delete the message for all admins
        while ($admin = $result->fetch_assoc()) {
            $admin_id = $admin['userid'];
            deleteMessage($admin_id, $message_id);
        }

        // Send confirmation messages
        sendMessage($chat_id, "✅ درخواست افزایش اعتبار کاربر $user_id به مبلغ " . number_format($amount) . " تومان تأیید شد.");
        
        // ارسال پیام به کاربر
        if ($user_id > 0) {
            $user_msg_result = sendMessage($user_id, "✅ درخواست افزایش اعتبار شما به مبلغ " . number_format($amount) . " تومان تأیید شد.");
            $logger->info("User notification sent", [
                'user_id' => $user_id,
                'success' => $user_msg_result ? 'true' : 'false'
            ]);
        }
        
        // ارسال پیام به کانال لاگ
        $log_message = "✅ *افزایش موجودی تأیید شد*\n\n" .
                       "👤 کاربر: [$user_name](tg://user?id=$user_id)\n" .
                       "🆔 آیدی: `$user_id`\n" .
                       "👤 یوزرنیم: @$escaped_username\n" .
                       "💰 مبلغ: " . number_format($amount) . " تومان\n" .
                       "⏱ زمان: " . date('Y-m-d H:i:s') . "\n" .
                       "👮‍♂️ تأیید توسط: @$admin_username";
        
        $log_result = sendToLogChannel($log_message);
        $logger->info("Log message sent result", ['success' => $log_result ? 'true' : 'false']);
        
        if (!$log_result) {
            // در صورت شکست در ارسال به کانال لاگ، یک بار دیگر تلاش کنیم
            sleep(1);
            $log_result = sendToLogChannel($log_message);
            $logger->info("Second attempt log message sent result", ['success' => $log_result ? 'true' : 'false']);
        }

    } catch (Exception $e) {
        $conn->rollback();
        $logger->error("Error in handleAdminApproveBalance", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        sendMessage($chat_id, "❌ خطا در پردازش درخواست: " . $e->getMessage());
    }
}

// Add this new function to delete messages
function deleteMessage($chat_id, $message_id) {
    global $logger;
    $logger->info("Deleting message", [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);

    global $token;
    $url = "https://api.telegram.org/bot$token/deleteMessage";
    
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ];
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    if ($result === false) {
        error_log("Failed to delete message for chat_id: $chat_id, message_id: $message_id");
        return false;
    }
    
    return true;
}

function handleAdminRejectBalance($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];
    $data = $callback_query['data'];
    $admin_id = $callback_query['from']['id'];
    $admin_username = $callback_query['from']['username'] ?? 'بدون نام کاربری';

    // لاگ کامل اطلاعات کالبک کوئری برای اشکال زدایی
    $logger->info("Callback query full data for reject", [
        'callback_query' => json_encode($callback_query)
    ]);

    $logger->info("Handling admin balance rejection", [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'callback_data' => $data,
        'admin_id' => $admin_id,
        'admin_username' => $admin_username
    ]);

    // استخراج اطلاعات از callback_data با دقت بیشتر
    $data_parts = explode('_', $data);
    $logger->info("Data parts for reject", ['parts' => json_encode($data_parts), 'count' => count($data_parts)]);
    
    if (count($data_parts) < 4) {
        $logger->error("Invalid callback data format for reject", ['data' => $data, 'parts_count' => count($data_parts)]);
        editMessage($chat_id, $message_id, "⚠️ فرمت داده‌ها نامعتبر است. تعداد بخش‌ها: " . count($data_parts));
        return;
    }
    
    // استخراج شناسه درخواست از callback_data
    $request_id = intval($data_parts[3]);
    
    if ($request_id <= 0) {
        $logger->error("Invalid request_id for reject", ['request_id' => $request_id, 'data' => $data]);
        editMessage($chat_id, $message_id, "⚠️ شناسه درخواست نامعتبر است: $request_id");
        return;
    }
    
    $logger->info("Extracted request_id for reject", [
        'request_id' => $request_id
    ]);

    try {
        // Start transaction
        $conn->begin_transaction();

        // دریافت اطلاعات درخواست از طریق شناسه درخواست
        $query = "SELECT id, user_id, status, amount FROM wallet_recharge_requests WHERE id = ? AND status = 'pending'";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $logger->info("Request query for reject", [
            'query' => $query,
            'request_id' => $request_id,
            'result_count' => $result->num_rows
        ]);

        if ($result->num_rows === 0) {
            $logger->error("No pending request found for reject", ['request_id' => $request_id]);
            editMessage($chat_id, $message_id, "⚠️ درخواست در حال انتظاری با شناسه $request_id یافت نشد.");
            return;
        }

        // دریافت اطلاعات درخواست
        $request = $result->fetch_assoc();
        $user_id = $request['user_id'];
        $amount = $request['amount']; // استفاده از مقدار دقیق ذخیره شده در دیتابیس
        
        $logger->info("Request details for reject", [
            'request_id' => $request_id,
            'user_id' => $user_id,
            'amount' => $amount
        ]);
        
        // Get user information
        $stmt = $conn->prepare("SELECT name, username FROM users WHERE userid = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_info = $result->fetch_assoc();
        
        if (!$user_info) {
            $logger->error("User info not found for reject", ['user_id' => $user_id]);
            throw new Exception("اطلاعات کاربر یافت نشد");
        }
        
        $user_name = $user_info['name'] ?? 'بدون نام';
        $user_username = $user_info['username'] ?? 'بدون نام کاربری';

        // اصلاح نام کاربری برای مارک‌داون تلگرام
        $escaped_username = str_replace('_', '\_', $user_username);

        // Update wallet_recharge_requests status
        $stmt = $conn->prepare("UPDATE wallet_recharge_requests SET status = 'rejected', approved_at = NOW(), approved_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $admin_id, $request_id);
        if (!$stmt->execute()) {
            $logger->error("Failed to update request status for reject", [
                'error' => $stmt->error,
                'request_id' => $request_id
            ]);
            throw new Exception("خطا در بروزرسانی وضعیت درخواست: " . $stmt->error);
        }
        
        $affected_rows = $stmt->affected_rows;
        if ($affected_rows <= 0) {
            $logger->warning("No rows affected when updating request status for reject", [
                'request_id' => $request_id
            ]);
        }

        // Delete user state
        $stmt = $conn->prepare("DELETE FROM user_states WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        if (!$stmt->execute()) {
            $logger->error("Failed to delete user state for reject", [
                'error' => $stmt->error,
                'user_id' => $user_id
            ]);
            throw new Exception("خطا در حذف وضعیت کاربر: " . $stmt->error);
        }

        $conn->commit();
        $logger->info("Balance recharge rejected successfully", [
            'user_id' => $user_id,
            'amount' => $amount
        ]);

        // Get all admins
        $stmt = $conn->prepare("SELECT userid FROM admins");
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Delete the message for all admins
        while ($admin = $result->fetch_assoc()) {
            $admin_id = $admin['userid'];
            deleteMessage($admin_id, $message_id);
        }

        // ارسال پیام به ادمین
        sendMessage($chat_id, "❌ درخواست افزایش اعتبار کاربر $user_id به مبلغ " . number_format($amount) . " تومان رد شد.");
        
        // ارسال پیام به کاربر
        if($user_id > 0) {
            sendMessage($user_id, "❌ درخواست افزایش اعتبار شما به مبلغ " . number_format($amount) . " تومان رد شد.");
        }
        
        // ارسال پیام به کانال لاگ
        $log_message = "❌ *درخواست افزایش موجودی رد شد*\n\n" .
                       "👤 کاربر: [$user_name](tg://user?id=$user_id)\n" .
                       "🆔 آیدی: `$user_id`\n" .
                       "👤 یوزرنیم: @$escaped_username\n" .
                       "💰 مبلغ: " . number_format($amount) . " تومان\n" .
                       "⏱ زمان: " . date('Y-m-d H:i:s') . "\n" .
                       "👮‍♂️ رد شده توسط: @$admin_username";
        
        $log_result = sendToLogChannel($log_message);
        $logger->info("Log message sent result for reject", ['success' => $log_result ? 'true' : 'false']);
        
        if (!$log_result) {
            // در صورت شکست در ارسال به کانال لاگ، یک بار دیگر تلاش کنیم
            sleep(1);
            $log_result = sendToLogChannel($log_message);
            $logger->info("Second attempt log message sent result for reject", ['success' => $log_result ? 'true' : 'false']);
        }
    } catch (Exception $e) {
        $conn->rollback();
        $logger->error("Error in handleAdminRejectBalance", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        sendMessage($chat_id, "❌ خطا در پردازش درخواست: " . $e->getMessage());
    }
}

function handleConfigDetails($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];
    $callback_data = $callback_query['data'];
    
    // حذف بررسی config_page_ - اضافه کردن لاگینگ بیشتر
    $logger->info("Received callback_data in handleConfigDetails", [
        'callback_data' => $callback_data,
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);
    
    $config_email = substr($callback_data, 7); // حذف 'config_' از ابتدای رشته

    $logger->info("Handling config details request", [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'callback_data' => $callback_data,
        'extracted_email' => $config_email
    ]);

    try {
        // بررسی اینکه ایمیل خالی نباشد
        if (empty($config_email)) {
            $logger->error("Empty config email extracted from callback_data", [
                'callback_data' => $callback_data
            ]);
            throw new Exception("آدرس ایمیل کانفیگ خالی است. callback_data: " . $callback_data);
        }

        // دریافت اطلاعات کانفیگ و سرور
        $stmt = $conn->prepare("SELECT uc.*, s.url, s.cookies, s.name as server_name 
                               FROM usersconfig uc 
                               JOIN servers s ON uc.server_id = s.id 
                               WHERE uc.email_config = ?");
        if (!$stmt) {
            throw new Exception("خطا در آماده‌سازی کوئری: " . $conn->error);
        }
        
        $stmt->bind_param('s', $config_email);
        if (!$stmt->execute()) {
            throw new Exception("خطا در اجرای کوئری: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        if (!$result) {
            throw new Exception("خطا در دریافت نتیجه کوئری: " . $stmt->error);
        }
        
        if ($result->num_rows === 0) {
            throw new Exception("کانفیگ با ایمیل $config_email یافت نشد.");
        }

        $config = $result->fetch_assoc();
        $panel_url = rtrim($config['url'], '/') . '/panel/inbound/list';
        
        // لاگ کردن پارامترهای درخواست
        $logger->info("Request parameters for getdatapanel.php:", [
            'panel_url' => $panel_url,
            'email' => $config_email,
            'cookie_length' => strlen($config['cookies'])
        ]);
        
        // ارسال درخواست به API با CURL
        $ch = curl_init();
        
        // تنظیم آدرس اسکریپت getdatapanel.php
        $target_url = 'https://jorabin.ir/bot/pages/tel/modules/getdatapanel.php';
        
        // تنظیم پارامترهای درخواست
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
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($curl_error) {
            throw new Exception("خطا در ارسال درخواست: $curl_error");
        }
        
        // لاگ کردن پاسخ دریافتی
        $logger->info("Response from getdatapanel.php:", [
            'http_code' => $http_code,
            'response_length' => strlen($response)
        ]);

        // ذخیره پاسخ در فایل txt
        $log_dir = __DIR__ . '/logs';
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0777, true);
        }
        
        $log_file = $log_dir . '/panel_responses.txt';
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "\n\n[$timestamp] Response for email: $config_email\n";
        $log_entry .= "Panel URL: $panel_url\n";
        $log_entry .= "Response: $response\n";
        $log_entry .= "----------------------------------------\n";
        
        file_put_contents($log_file, $log_entry, FILE_APPEND);

        // ساخت دکمه بازگشت به لیست کانفیگ‌ها
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به لیست کانفیگ‌ها', 'callback_data' => 'show_my_configs']]
            ]
        ];

        $data = json_decode($response, true);
        
        if (!is_array($data)) {
            throw new Exception("پاسخ دریافتی نامعتبر است: " . substr($response, 0, 100) . "...");
        }

        if (!isset($data['success']) || !$data['success']) {
            throw new Exception($data['msg'] ?? "خطا در دریافت اطلاعات کانفیگ");
        }

        if (!isset($data['data'])) {
            throw new Exception("داده‌های کانفیگ در پاسخ یافت نشد");
        }

        $info = $data['data'];
        
        // تبدیل تاریخ به فرمت فارسی
        $expiry_date = new DateTime();
        $expiry_date->setTimestamp($info['expiryTime']);
        $jalali_date = gregorian_to_jalali(
            (int)$expiry_date->format('Y'),
            (int)$expiry_date->format('m'),
            (int)$expiry_date->format('d')
        );
        
        // تبدیل حجم کل از بایت به گیگابایت
        $total_gb = round($info['totalGB'] / (1024 * 1024 * 1024), 2);
        
        // محاسبه حجم باقیمانده
        $remaining_gb = $total_gb - $info['usedTrafficGB'];
        $remaining_gb = max(0, $remaining_gb); // جلوگیری از منفی شدن

        $message = "🔍 جزئیات کانفیگ شما:\n\n";
        $message .= "📛 نام کانفیگ: {$config['name_config']}\n";
        $message .= "📧 ایمیل: {$config['email_config']}\n";
        $message .= "🖥 سرور: {$config['server_name']}\n\n";
        $message .= "📅 تاریخ انقضا: {$jalali_date[0]}/{$jalali_date[1]}/{$jalali_date[2]}\n";
        $message .= "⏳ روزهای باقیمانده: {$info['remainingDays']} روز\n";
        $message .= "💾 حجم کل: {$total_gb} گیگابایت\n";
        $message .= "📊 حجم مصرف شده: {$info['totalUsage']}\n";
        $message .= "📈 حجم باقیمانده: " . number_format($remaining_gb, 2) . " گیگابایت\n";

        // Check if config needs renewal based on three conditions:
        // 1. enable is false
        // 2. total traffic (up + down) >= totalGB
        // 3. expiry time has passed
        $total_used = $info['up'] + $info['down'];
        $today = time();
        $needs_renewal = !$info['enable'] || $total_used >= $info['totalGB'] || $info['expiryTime'] <= $today;

        // Determine status based on all conditions
        $status = "✅ فعال";
        if (!$info['enable']) {
            $status = "❌ غیرفعال (غیرفعال شده)";
        } elseif ($total_used >= $info['totalGB']) {
            $status = "❌ غیرفعال (حجم تموم شده)";
        } elseif ($info['expiryTime'] <= $today) {
            $status = "❌ غیرفعال (تاریخ انقضا گذشته)";
        }

        $message .= "🔌 وضعیت: {$status}\n";

        // Add config links
        $message .= "\n🔄 *لینک سابسکریپشن:*\n`https://jorabin.ir/bot/pages/config_generator.php?email=" . trim($config_email) . "`\n\n";
        
        if (!empty($config['config_c'])) {
            $message .= "🔗 *لینک کانفیگ عادی:*\n```\n" . trim($config['config_c']) . "\n```";
        }
        if (!empty($config['configtunnel_c'])) {
            $message .= "\n🔗 *لینک کانفیگ تونل شده:*\n```\n" . trim($config['configtunnel_c']) . "\n```";
        }

        // Add renewal button if any of the conditions are met
        if ($needs_renewal) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔄 تمدید کانفیگ', 'callback_data' => 'renew_config_' . $config_email],
                        ['text' => '🗑 حذف کانفیگ', 'callback_data' => 'delete_config_' . $config_email]
                    ],
                    [['text' => '🔙 بازگشت به لیست کانفیگ‌ها', 'callback_data' => 'show_my_configs']]
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🗑 حذف کانفیگ', 'callback_data' => 'delete_config_' . $config_email]
                    ],
                    [['text' => '🔙 بازگشت به لیست کانفیگ‌ها', 'callback_data' => 'show_my_configs']]
                ]
            ];
        }

        // اگر کانفیگ تونل شده وجود داشته باشد، QR کد آن را نیز ارسال می‌کنیم
        if (!empty($config['configtunnel_c'])) {
            $tunnel_config = trim($config['configtunnel_c']);
            
            // ایجاد دایرکتوری برای ذخیره موقت تصاویر QR
            $upload_dir = __DIR__ . "/../../uploads/qrcodes/";
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // ایجاد نام فایل منحصر به فرد
            $file_name = md5($tunnel_config . time()) . '.png';
            $file_path = $upload_dir . $file_name;
            
            try {
                // استفاده از سرویس QR کد آنلاین
                $qr_service_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($tunnel_config);
                $qr_data = @file_get_contents($qr_service_url);
                
                if ($qr_data === false) {
                    // اگر سرویس اول کار نکرد، از سرویس جایگزین استفاده می‌کنیم
                    $logger->info("First QR service failed, trying alternative");
                    $qr_service_url_alt = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($tunnel_config);
                    $qr_data = @file_get_contents($qr_service_url_alt);
                }
                
                // اگر هنوز نتوانستیم QR بسازیم، فقط پیام متنی می‌فرستیم
                if ($qr_data === false) {
                    $logger->error("Both QR services failed");
                    editMessage($chat_id, $message_id, $message, $keyboard);
                    return;
                }
                
                // ذخیره داده QR در فایل
                file_put_contents($file_path, $qr_data);
                
                // حذف پیام قبلی
                deleteMessage($chat_id, $message_id);
                
                // ارسال تصویر QR
                $file_sent = sendPhoto($chat_id, $file_path, $message, $keyboard);
                
                // حذف فایل موقت
                @unlink($file_path);
                
                // اگر ارسال فایل موفق نبود، پیام متنی ارسال می‌کنیم
                if (!$file_sent) {
                    $logger->error("Failed to send photo, sending text message instead");
                    sendMessage($chat_id, $message, $keyboard);
                }
            } catch (Exception $e) {
                $logger->error("Error in QR code creation", [
                    'error' => $e->getMessage()
                ]);
                
                // در صورت بروز خطا، پیام متنی ارسال می‌کنیم
                editMessage($chat_id, $message_id, $message, $keyboard);
            }
        } else {
            // اگر کانفیگ تونل شده نداشت، فقط پیام متنی ارسال می‌کنیم
            editMessage($chat_id, $message_id, $message, $keyboard);
        }
        
    } catch (Exception $e) {
        $logger->error("Error in handleConfigDetails", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'config_email' => $config_email
        ]);
        
        $error_message = "❌ خطا در دریافت اطلاعات سرویس:\n" . $e->getMessage();
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔄 تلاش مجدد', 'callback_data' => 'config_' . $config_email]],
                [['text' => '🔙 بازگشت به لیست کانفیگ‌ها', 'callback_data' => 'show_my_configs']]
            ]
        ];
        
        editMessage($chat_id, $message_id, $error_message, $keyboard);
        error_log("Error in handleConfigDetails: " . $e->getMessage());
    }
}

/**
 * دریافت نام سرور بر اساس server_id
 */
function getServerNameById($conn, $server_id) {
    global $logger;
    $logger->info("Getting server name by ID", [
        'server_id' => $server_id
    ]);

    try {
        $stmt = $conn->prepare("SELECT name FROM servers WHERE id = ?");
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['name'];
        }
        return 'سرور ناشناخته';
    } catch (Exception $e) {
        error_log("Error in getServerNameById: " . $e->getMessage());
        return 'سرور ناشناخته';
    }
}


function gregorian_to_jalali($gy, $gm, $gd) {
    global $logger;
    $logger->debug("Converting Gregorian to Jalali", [
        'gregorian' => "$gy-$gm-$gd"
    ]);

    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function showMyConfigs($chat_id, $conn, $page = 1, $message_id = null) {
    global $logger;
    $logger->info("Showing user configs", [
        'chat_id' => $chat_id,
        'page' => $page,
        'message_id' => $message_id
    ]);

    try {
        $user_id = $chat_id;
        $configs_per_page = 5;
        $offset = ($page - 1) * $configs_per_page;
        
        // Get total count of configs
        $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM usersconfig WHERE userid_c = ?");
        $count_stmt->bind_param('i', $user_id);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $total_configs = $count_result->fetch_assoc()['total'];
        $total_pages = ceil($total_configs / $configs_per_page);

        // Get configs for this user with pagination
        $stmt = $conn->prepare("SELECT uc.*, s.name as server_name, s.url, s.cookies, p.volume_gb, p.days_count, p.price 
                               FROM usersconfig uc 
                               JOIN servers s ON uc.server_id = s.id 
                               JOIN products p ON uc.config_id = p.id 
                               WHERE uc.userid_c = ?
                               ORDER BY uc.id DESC
                               LIMIT ? OFFSET ?");
        if (!$stmt) {
            writeLog("Error preparing configs query: " . $conn->error);
            throw new Exception("خطا در آماده‌سازی کوئری کانفیگ‌ها");
        }

        $stmt->bind_param('iii', $user_id, $configs_per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result || $result->num_rows === 0) {
            $message = "❌ شما هیچ کانفیگی ندارید.";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔙 بازگشت به حساب کاربری', 'callback_data' => 'back_to_purchases']]
                ]
            ];
            
            if ($message_id) {
                editMessage($chat_id, $message_id, $message, $keyboard);
            } else {
                sendMessage($chat_id, $message, $keyboard);
            }
            return;
        }

        // دریافت اطلاعات موجودی کاربر
        $user_stmt = $conn->prepare("SELECT balance FROM users WHERE userid = ?");
        $user_stmt->bind_param('i', $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user = $user_result->fetch_assoc();
        $balance = $user ? $user['balance'] : 0;

        $message = "📋 لیست کانفیگ‌های شما (صفحه $page از $total_pages):\n";
        $message .= "💰 موجودی حساب شما: " . number_format($balance) . " تومان\n\n";
        
        $keyboard = [
            'inline_keyboard' => []
        ];

        $config_count = 0;
        while (($config = $result->fetch_assoc()) !== false && $config_count < $configs_per_page) {
            $config_count++;
            // اضافه کردن دکمه برای هر کانفیگ
            $keyboard['inline_keyboard'][] = [
                ['text' => "📌 {$config['name_config']} - {$config['server_name']}", 'callback_data' => 'config_' . $config['email_config']]
            ];
        }

        // Add navigation buttons
        $nav_buttons = [];
        if ($page > 1) {
            $nav_buttons[] = ['text' => '◀️ صفحه قبل', 'callback_data' => 'config_page_' . ($page - 1)];
        }
        if ($page < $total_pages) {
            $nav_buttons[] = ['text' => 'صفحه بعد ▶️', 'callback_data' => 'config_page_' . ($page + 1)];
        }
        if (!empty($nav_buttons)) {
            $keyboard['inline_keyboard'][] = $nav_buttons;
        }

        $keyboard['inline_keyboard'][] = [
            ['text' => '🔙 بازگشت به حساب کاربری', 'callback_data' => 'back_to_purchases']
        ];

        if ($message_id) {
            editMessage($chat_id, $message_id, $message, $keyboard);
        } else {
            sendMessage($chat_id, $message, $keyboard);
        }
    } catch (Exception $e) {
        $error_message = "❌ خطا در دریافت لیست کانفیگ‌ها: " . $e->getMessage();
        if ($message_id) {
            editMessage($chat_id, $message_id, $error_message);
        } else {
            sendMessage($chat_id, $error_message);
        }
        $logger->error("Error in showMyConfigs", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

/**
 * Log config operations
 */
function logConfigOperation($conn, $user_id, $config_email, $action, $server_id, $config_details = null, $status = 'success', $error_message = null) {
    global $logger;
    
    try {
        $stmt = $conn->prepare("INSERT INTO config_logs (user_id, config_email, action, server_id, config_details, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            $logger->error("Error preparing log query", ['error' => $conn->error]);
            return false;
        }

        $config_details_json = $config_details ? json_encode($config_details) : null;
        $stmt->bind_param('ississs', $user_id, $config_email, $action, $server_id, $config_details_json, $status, $error_message);
        
        if (!$stmt->execute()) {
            $logger->error("Error executing log query", ['error' => $stmt->error]);
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        $logger->error("Error in logConfigOperation", ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Handle config deletion
 */
function handleDeleteConfig($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];
    $data = $callback_query['data'];
    
    // Extract email from callback_data (remove 'delete_config_' prefix)
    $config_email = substr($data, 13); // 'delete_config_' is 13 characters long
    
    // Remove any leading underscore from the email
    $config_email = str_replace('_', '', $config_email);
    
    // Log the raw data for debugging
    $logger->info("Raw callback data", [
        'data' => $data,
        'extracted_email' => $config_email,
        'data_length' => strlen($data),
        'prefix_length' => 13
    ]);

    try {
        // Get config and server details
        $stmt = $conn->prepare("SELECT uc.*, s.url, s.cookies, s.name as server_name 
                               FROM usersconfig uc 
                               JOIN servers s ON uc.server_id = s.id 
                               WHERE uc.email_config = ?");
        if (!$stmt) {
            $error = $conn->error;
            $logger->error("Error preparing config query", [
                'error' => $error,
                'config_email' => $config_email
            ]);
            throw new Exception("خطا در آماده‌سازی کوئری: " . $error);
        }

        $stmt->bind_param('s', $config_email);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $logger->error("Error executing config query", [
                'error' => $error,
                'config_email' => $config_email
            ]);
            throw new Exception("خطا در اجرای کوئری: " . $error);
        }

        $result = $stmt->get_result();
        if (!$result) {
            $error = $stmt->error;
            $logger->error("Error getting result from config query", [
                'error' => $error,
                'config_email' => $config_email
            ]);
            throw new Exception("خطا در دریافت نتیجه کوئری: " . $error);
        }
        
        if ($result->num_rows === 0) {
            $logger->error("No config found for email", [
                'config_email' => $config_email
            ]);
            throw new Exception("کانفیگ با ایمیل {$config_email} یافت نشد.");
        }

        $config = $result->fetch_assoc();
        $logger->info("Config details retrieved", [
            'config' => $config
        ]);

        // Log the delete attempt
        logConfigOperation(
            $conn,
            $config['userid_c'],
            $config_email,
            'delete',
            $config['server_id'],
            $config,
            'pending'
        );

        $server_url = rtrim($config['url'], '/');
        
        // Get config details to get the UUID
        $panel_url = $server_url . '/panel/inbound/list';
        $ch = curl_init();
        $target_url = 'https://jorabin.ir/bot/pages/tel/modules/getdatapanel.php';
        $postData = http_build_query([
            'url' => $panel_url,
            'email' => $config_email,
            'cookie' => $config['cookies']
        ]);
        
        $logger->info("Sending request to getdatapanel.php", [
            'target_url' => $target_url,
            'panel_url' => $panel_url,
            'email' => $config_email
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
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            $logger->error("CURL error in getdatapanel.php request", [
                'error' => $curl_error,
                'http_code' => $http_code
            ]);
            throw new Exception("خطا در ارسال درخواست به سرور: " . $curl_error);
        }

        if ($http_code !== 200) {
            $logger->error("Invalid HTTP response from getdatapanel.php", [
                'http_code' => $http_code,
                'response' => $response
            ]);
            throw new Exception("خطا در دریافت پاسخ از سرور. کد وضعیت: " . $http_code);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logger->error("Error decoding JSON response", [
                'json_error' => json_last_error_msg(),
                'response' => $response
            ]);
            throw new Exception("خطا در پردازش پاسخ سرور: " . json_last_error_msg());
        }

        if (!isset($data['data']['id'])) {
            $logger->error("Missing UUID in response", [
                'response' => $data
            ]);
            throw new Exception("شناسه کانفیگ در پاسخ یافت نشد.");
        }

        $uuid = $data['data']['id'];
        $inbound_id = 4; // As specified in the URL

        // Send delete request to panel
        $delete_url = $server_url . "/panel/inbound/$inbound_id/delClient/$uuid";
        $logger->info("Sending delete request to panel", [
            'delete_url' => $delete_url
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $delete_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Cookie: ' . $config['cookies']
            ]
        ]);

        $delete_response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            $logger->error("CURL error in delete request", [
                'error' => $curl_error,
                'http_code' => $http_code
            ]);
            throw new Exception("خطا در ارسال درخواست حذف: " . $curl_error);
        }

        if ($http_code !== 200) {
            $logger->error("Invalid HTTP response from delete request", [
                'http_code' => $http_code,
                'response' => $delete_response
            ]);
            throw new Exception("خطا در حذف کانفیگ از سرور. کد وضعیت: " . $http_code);
        }

        // Delete from database
        $stmt = $conn->prepare("DELETE FROM usersconfig WHERE email_config = ?");
        if (!$stmt) {
            $error = $conn->error;
            $logger->error("Error preparing delete query", [
                'error' => $error
            ]);
            throw new Exception("خطا در آماده‌سازی کوئری حذف: " . $error);
        }

        $stmt->bind_param('s', $config_email);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $logger->error("Error executing delete query", [
                'error' => $error
            ]);
            throw new Exception("خطا در اجرای کوئری حذف: " . $error);
        }

        // Update user's config count
        $stmt = $conn->prepare("UPDATE users SET configcount = configcount - 1 WHERE userid = ?");
        if (!$stmt) {
            $error = $conn->error;
            $logger->error("Error preparing update query", [
                'error' => $error
            ]);
            throw new Exception("خطا در آماده‌سازی کوئری بروزرسانی: " . $error);
        }

        $stmt->bind_param('i', $config['userid_c']);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $logger->error("Error executing update query", [
                'error' => $error
            ]);
            throw new Exception("خطا در اجرای کوئری بروزرسانی: " . $error);
        }

        // Log successful deletion
        logConfigOperation(
            $conn,
            $config['userid_c'] ?? 0,
            $config_email,
            'delete',
            $config['server_id'] ?? 0,
            $config ?? null,
            'success'
        );

        $message = "✅ کانفیگ با موفقیت حذف شد.";
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به لیست کانفیگ‌ها', 'callback_data' => 'show_my_configs']]
            ]
        ];

        editMessage($chat_id, $message_id, $message, $keyboard);

    } catch (Exception $e) {
        // Log failed deletion
        logConfigOperation(
            $conn,
            $config['userid_c'] ?? 0,
            $config_email,
            'delete',
            $config['server_id'] ?? 0,
            $config ?? null,
            'failed',
            $e->getMessage()
        );

        $error_message = "❌ خطا در حذف کانفیگ:\n" . $e->getMessage();
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔄 تلاش مجدد', 'callback_data' => 'delete_config_' . $config_email]],
                [['text' => '🔙 بازگشت به لیست کانفیگ‌ها', 'callback_data' => 'show_my_configs']]
            ]
        ];
        editMessage($chat_id, $message_id, $error_message, $keyboard);
        $logger->error("Error in handleDeleteConfig", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}