<?php
// modules/buy_service.php

require_once __DIR__ . '/../utils/Logger.php';

$logger = Logger::getInstance();

// تعریف متغیرهای سراسری
$GLOBALS['purchase_message'] = ""; // پیام اصلی خرید کانفیگ

if (!defined('TOKEN')) {
    die('Direct access not permitted');
}

/**
 * مدیریت نمایش لیست سرورها
 */
function handleModule($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];

    $logger->info("Handling buy service module", [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);

    // دریافت لیست سرورها از دیتابیس
    $servers = getServersFromDatabase($conn);

    if (empty($servers)) {
        editMessage($chat_id, $message_id, "در حال حاضر هیچ سروری در دسترس نیست.");
        return;
    }

    // ساخت دکمه‌های شیشه‌ای برای سرورها
    $keyboard = [
        'inline_keyboard' => array_map(fn($server) => [
            ['text' => $server['name'], 'callback_data' => 'server_' . $server['id']]
        ], $servers)
    ];

    // افزودن دکمه بازگشت به منوی اصلی
    $keyboard['inline_keyboard'][] = [
        ['text' => 'بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']
    ];

    // به‌روزرسانی پیام با لیست سرورها
    editMessage(
        $chat_id, 
        $message_id, 
        "📡 لطفاً یکی از سرورهای زیر را انتخاب کنید:\n\n⚡️ سرعت و کیفیت تمامی سرورها تضمین شده است.",
        $keyboard
    );
}

/**
 * مدیریت انتخاب سرور
 */
function handleServerSelection($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];

    $logger->info("Handling server selection", [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);

    $data = $callback_query['data'];
    $server_id = str_replace('server_', '', $data);

    // دریافت محصولات سرور انتخاب شده
    $products = getProductsByServer($conn, $server_id);

    if (empty($products)) {
        editMessage($chat_id, $message_id, "در حال حاضر هیچ محصولی برای این سرور موجود نیست.");
        return;
    }

    // ساخت دکمه‌های شیشه‌ای برای محصولات
    $keyboard = [
        'inline_keyboard' => array_map(fn($product) => [
            ['text' => $product['product_name'], 'callback_data' => 'product_' . $product['id'] . '_server_' . $server_id]
        ], $products)
    ];

    // افزودن دکمه بازگشت
    $keyboard['inline_keyboard'][] = [
        ['text' => 'بازگشت به لیست سرورها', 'callback_data' => 'buy_service']
    ];

    // به‌روزرسانی پیام با لیست محصولات
    editMessage(
        $chat_id,
        $message_id,
        "🛍 لطفاً یکی از پلن‌های زیر را انتخاب کنید:",
        $keyboard
    );
}

/**
 * مدیریت خرید محصول و ارسال درخواست POST به سرور
 */
function processEmptyArrays($array) {
    global $logger;
    $logger->debug("Processing empty arrays", [
        'array' => $array
    ]);

    foreach ($array as $key => $value) {
        if (is_array($value)) {
            if (empty($value)) {
                $array[$key] = new stdClass();
            } else {
                $array[$key] = processEmptyArrays($value);
            }
        }
    }
    return $array;
}

function prepareConfigSettings($config_array) {
    global $logger;
    $logger->debug("Preparing config settings", [
        'config_array' => $config_array
    ]);

    // پردازش آرایه‌های خالی در تنظیمات
    $settings = json_decode(urldecode($config_array['settings']), true);
    $streamSettings = json_decode(urldecode($config_array['streamSettings']), true);
    $sniffing = json_decode(urldecode($config_array['sniffing']), true);
    $allocate = json_decode(urldecode($config_array['allocate']), true);

    // تبدیل آرایه‌های خالی به آبجکت
    $settings = processEmptyArrays($settings);
    $streamSettings = processEmptyArrays($streamSettings);
    $sniffing = processEmptyArrays($sniffing);
    $allocate = processEmptyArrays($allocate);

    // تبدیل مجدد به JSON
    $config_array['settings'] = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $config_array['streamSettings'] = json_encode($streamSettings, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $config_array['sniffing'] = json_encode($sniffing, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $config_array['allocate'] = json_encode($allocate, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return $config_array;
}

function preserveStructure($originalValue, $newValue) {
    global $logger;
    $logger->debug("Preserving structure", [
        'original_value' => $originalValue,
        'new_value' => $newValue
    ]);

    if (is_array($originalValue) && is_array($newValue)) {
        $result = [];
        foreach ($originalValue as $key => $value) {
            if (array_key_exists($key, $newValue)) {
                $result[$key] = preserveStructure($value, $newValue[$key]);
            } else {
                $result[$key] = $value;
            }
        }
        // حفظ ساختار آرایه خالی اصلی
        if (empty($originalValue) && is_array($originalValue)) {
            return [];
        }
        return $result;
    }
    return $newValue ?? $originalValue;
}

function handleBuyProduct($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];

    $logger->info("Handling buy product request", [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);

    $data = $callback_query['data'];
    $user_id = $callback_query['from']['id'];

    // Extract product_id and server_id from callback_data
    $parts = explode('_', $data);
    $product_id = $parts[2];
    $server_id = $parts[4];

    // دریافت اطلاعات محصول
    $product = getProductDetails($conn, $product_id);
    if (!$product) {
        editMessage($chat_id, $message_id, "⚠️ خطا در دریافت اطلاعات محصول.");
        return;
    }

    // دریافت موجودی کاربر
    $stmt = $conn->prepare("SELECT balance FROM users WHERE userid = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user || $user['balance'] < $product['price']) {
        editMessage($chat_id, $message_id, "⚠️ موجودی کیف پول شما کافی نیست. لطفاً حساب خود را شارژ کنید.");
        return;
    }

    // چک کنیم آیا نام سرویس از قبل در temp_data ذخیره شده
    if (isset($GLOBALS['service_name'])) {
        processPurchaseWithName($callback_query, $conn, $GLOBALS['service_name']);
        return;
    }

    try {
        // ذخیره اطلاعات موقت در جدول user_states
        $temp_data = json_encode([
            'product_id' => $product_id,
            'server_id' => $server_id
        ]);
        
        $stmt = $conn->prepare("INSERT INTO user_states (user_id, state, temp_data) 
                               VALUES (?, 'waiting_service_name', ?) 
                               ON DUPLICATE KEY UPDATE state = VALUES(state), temp_data = VALUES(temp_data)");
        $stmt->bind_param('is', $user_id, $temp_data);
        
        if (!$stmt->execute()) {
            throw new Exception("خطا در ذخیره وضعیت کاربر");
        }

        // درخواست نام سرویس از کاربر
        $message = "🏷 لطفاً یک نام برای سرویس خود وارد کنید:\n\n";
        $message .= "⚠️ نام وارد شده برای شناسایی راحت‌تر سرویس شما استفاده خواهد شد.";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '❌ انصراف', 'callback_data' => 'cancel_service_name']
                ]
            ]
        ];
        
        editMessage($chat_id, $message_id, $message, $keyboard);

    } catch (Exception $e) {
        error_log("Error in handleBuyProduct: " . $e->getMessage());
        editMessage($chat_id, $message_id, "⚠️ خطایی در پردازش درخواست رخ داد.");
    }
}

function processPurchaseWithName($callback_query, $conn, $service_name) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];

    $logger->info("Processing purchase with name", [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'service_name' => $service_name
    ]);

    $data = $callback_query['data'];
    $user_id = $callback_query['from']['id'];

    // Extract product_id and server_id from callback_data
    $parts = explode('_', $data);
    $product_id = $parts[2];
    $server_id = $parts[4];

    try {
        // دریافت اطلاعات محصول
        $product = getProductDetails($conn, $product_id);
        if (!$product) {
            editMessage($chat_id, $message_id, "⚠️ خطا در دریافت اطلاعات محصول.");
            return;
        }

        // ذخیره اطلاعات محصول در GLOBALS
        $GLOBALS['product'] = $product;

        // دریافت موجودی کاربر
        $stmt = $conn->prepare("SELECT balance FROM users WHERE userid = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if (!$user || $user['balance'] < $product['price']) {
            editMessage($chat_id, $message_id, "⚠️ موجودی کیف پول شما کافی نیست. لطفاً حساب خود را شارژ کنید.");
            return;
        }

        // کسر موجودی از کیف پول کاربر
        $new_balance = $user['balance'] - $product['price'];
        $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE userid = ?");
        $stmt->bind_param('ii', $new_balance, $user_id);
        $stmt->execute();

        // دریافت جزئیات سرور
        $server = getServerDetails($conn, $server_id);
        if (!$server) {
            editMessage($chat_id, $message_id, "⚠️ خطا در دریافت اطلاعات سرور.");
            return;
        }

        // ذخیره server_id در متغیر سراسری
        $GLOBALS['server_id'] = $server_id;

        // دریافت config_ids از محصول
        $config_ids = explode(',', $product['config_ids']);

        // دریافت config_id مرتبط با سرور
        $stmt = $conn->prepare("SELECT config_id FROM config_servers WHERE server_id = ?");
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $config_servers_result = $stmt->get_result();

        if ($config_servers_result && $config_servers_result->num_rows > 0) {
            $config_ids_from_server = [];
            while ($row = $config_servers_result->fetch_assoc()) {
                $config_ids_from_server[] = $row['config_id'];
            }

            // پیدا کردن config_id مشترک بین محصول و سرور
            $common_config_ids = array_intersect($config_ids, $config_ids_from_server);
            if (empty($common_config_ids)) {
                editMessage($chat_id, $message_id, "⚠️ هیچ کانفیگ مشترکی بین محصول و سرور یافت نشد.");
                return;
            }

            // دریافت اطلاعات کانفیگ
            $config_id = reset($common_config_ids);
            $stmt = $conn->prepare("SELECT config_settings, port_type, id FROM configs WHERE id = ?");
            $stmt->bind_param('i', $config_id);
            $stmt->execute();
            $config_result = $stmt->get_result();

            if ($config_result && $config_result->num_rows > 0) {
                $config_row = $config_result->fetch_assoc();
                $original_config_settings = $config_row['config_settings'];
                $port_type = $config_row['port_type'];
                $inbound_id = $config_row['id'];

                if ($port_type === 'single_port') {
                    // Parse original config settings
                    parse_str($original_config_settings, $config_array);

                    // Decode the settings JSON
                    $settings = json_decode($config_array['settings'], true);

                    // Generate new values
                    $new_email = generateRandomEmail();
                    $new_id = generateUUID();

                    // ذخیره ایمیل جدید در GLOBALS
                    $GLOBALS['new_email'] = $new_email;

                    // Update settings for the new client
                    $new_client = [
                        'id' => $new_id,
                        'email' => $new_email,
                        'totalGB' => convertGBToBytes($product['volume_gb']),
                        'expiryTime' => calculateExpiryTimestamp($product['days_count']),
                        'subId' => substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 16)
                    ];

                    // Add any existing fields from the original client
                    if (!empty($settings['clients'][0])) {
                        foreach ($settings['clients'][0] as $key => $value) {
                            if (!isset($new_client[$key])) {
                                $new_client[$key] = $value;
                            }
                        }
                    }

                    // Update the client in settings
                    $settings['clients'] = [$new_client];

                    // Encode updated settings back to JSON
                    $config_array['settings'] = json_encode($settings, JSON_UNESCAPED_SLASHES);

                    // Build config settings
                    $config_settings = http_build_query([
                        'id' => $config_array['id'],
                        'settings' => $config_array['settings']
                    ]);

                    // Send request to add client
                    $endpoint = '/panel/inbound/addClient';
                    $request_url = rtrim($server['url'], '/') . $endpoint;

                    $headers = [
                        "Cookie: " . $server['cookies'],
                        "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
                        "X-Requested-With: XMLHttpRequest"
                    ];

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $request_url);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $config_settings);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($http_code == 200) {
                        $response_data = json_decode($response, true);
                        if (isset($response_data['success']) && $response_data['success'] === true) {
                            // Get server address from URL
                            $server_url = $server['url'];
                            $serverAddress = parse_url($server_url, PHP_URL_HOST);

                            // Get inbound details
                            $get_inbound_url = rtrim($server['url'], '/') . "/panel/api/inbounds/get/{$config_array['id']}";
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $get_inbound_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            $inbound_response = curl_exec($ch);
                            curl_close($ch);

                            if ($inbound_response) {
                                $inbound_data = json_decode($inbound_response, true);
                                
                                // Update client details in inbound data
                                if (isset($inbound_data['obj']['settings'])) {
                                    $inbound_settings = json_decode($inbound_data['obj']['settings'], true);
                                    if (isset($inbound_settings['clients']) && is_array($inbound_settings['clients'])) {
                                        $inbound_settings['clients'] = [$new_client];
                                        $inbound_data['obj']['settings'] = json_encode($inbound_settings);
                                    }
                                }

                                // Send to genratexui.php with server address
                                $genratexui_data = [
                                    'obj' => [
                                        'settings' => json_encode($inbound_settings),
                                        'port' => $inbound_data['obj']['port'],
                                        'protocol' => $inbound_data['obj']['protocol'],
                                        'streamSettings' => $inbound_data['obj']['streamSettings'],
                                        'serverAddress' => $serverAddress,
                                        'remark' => $service_name
                                    ]
                                ];

                                $genratexui_response = sendToGenrateXUI($serverAddress, json_encode($genratexui_data));
                                $normal_config = str_replace("Generated Link: ", "", $genratexui_response);
                                
                                $message = "\n\n✨ *متشکریم از خرید شما*\n";
                                $message .= "📛 نام سرویس: " . $service_name . "\n";
                                $message .= "📧 ایمیل: " . $new_email . "\n";
                                $message .= "💾 حجم: " . $product['volume_gb'] . " گیگابایت\n";
                                $message .= "⏳ مدت زمان: " . $product['days_count'] . " روز\n\n";
                                $message .= "🔗 *لینک کانفیگ عادی:*\n```\n" . $normal_config . "\n```";

                                // ذخیره پیام خرید در متغیر سراسری
                                $GLOBALS['purchase_message'] = $message;
                                $GLOBALS['normal_config'] = $normal_config;

                                // Handle tunnel_ip if exists
                                if (!empty($server['tunnel_ip'])) {
                                    $tunnel_data = $genratexui_data;
                                    $tunnel_data['obj']['serverAddress'] = $server['tunnel_ip'];
                                    $tunnel_response = sendToGenrateXUI($server['tunnel_ip'], json_encode($tunnel_data));
                                    $tunnel_config = str_replace("Generated Link: ", "", $tunnel_response);
                                    
                                    $message .= "\n\n🔗 *لینک کانفیگ تونل شده:*\n```\n" . $tunnel_config . "\n```";
                                    
                                    // ذخیره تونل کانفیگ در متغیر سراسری
                                    $GLOBALS['tunnel_config'] = $tunnel_config;
                                    
                                    // تلاش برای ایجاد QR کد و ارسال یک پیام کامل
                                    $keyboard = [
                                        'inline_keyboard' => [
                                            [['text' => 'بازگشت به جزئیات محصول', 'callback_data' => 'product_' . $product_id . '_server_' . $server_id]]
                                        ]
                                    ];
                                    
                                    // ترکیب پیام‌های قبلی با کانفیگ تونل شده در یک پیام جامع
                                    $full_message = $message;
                                    
                                    // اضافه کردن لینک سابسکریپشن
                                    $full_message .= "\n\n🔄 *لینک سابسکریپشن:*\n`https://jorabin.ir/bot/pages/config_generator.php?email=" . $new_email . "`";
                                    
                                    // ارسال پیام با تصویر QR کد
                                    $qr_sent = createAndSendQRWithMessage($chat_id, $tunnel_config, $full_message, $keyboard);
                                    
                                    if ($qr_sent) {
                                        // اگر ارسال موفق بود، از ارسال پیام متنی جداگانه جلوگیری می‌کنیم
                                        $message = "";
                                    }
                                }

                                // Update user config count
                                $stmt = $conn->prepare("UPDATE users SET configcount = configcount + 1 WHERE userid = ?");
                                $stmt->bind_param('i', $user_id);
                                $stmt->execute();

                                // Save configs to database
                                $tunnel_config_value = !empty($tunnel_config) ? $tunnel_config : null;
                                $stmt = $conn->prepare("INSERT INTO usersconfig (userid_c, config_c, configtunnel_c, name_config, email_config, server_id, config_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                                $stmt->bind_param('issssii', $user_id, $normal_config, $tunnel_config_value, $service_name, $new_email, $server['id'], $product_id);
                                $stmt->execute();

                                // بعد از خرید موفق کانفیگ، لاگ را ثبت می‌کنیم
                                if ($response_data['success']) {
                                    // لاگ کردن خرید کانفیگ
                                    logConfigOperation(
                                        $conn,
                                        $user_id,
                                        $new_email,
                                        'create',
                                        $server_id,
                                        [
                                            'name_config' => $service_name,
                                            'volume_gb' => $product['volume_gb'],
                                            'days_count' => $product['days_count'],
                                            'price' => $product['price']
                                        ],
                                        'success'
                                    );
                                }
                            } else {
                                $message = "⚠️ خطا در دریافت اطلاعات inbound از سرور.";
                            }
                        } else {
                            $message = "⚠️ خطا در ارسال درخواست به سرور.\n";
                            $message .= "📝 کد وضعیت: " . $http_code . "\n";
                            $message .= "📝 پاسخ سرور:\n" . $response;
                        }
                    } else {
                        $message = "⚠️ خطا در ارسال درخواست به سرور.\n";
                        $message .= "📝 کد وضعیت: " . $http_code . "\n";
                        $message .= "📝 پاسخ سرور:\n" . $response;
                    }
                } elseif ($port_type === 'multi_port') {
                    // Convert config_settings to array
                    parse_str($original_config_settings, $original_config_array);
                    parse_str($original_config_settings, $config_array);

                    // Get original settings
                    $original_settings = json_decode(urldecode($original_config_array['settings']), true);

                    // Create new client settings
                    $new_client = [
                        'id' => generateUUID(),
                        'security' => 'auto',
                        'email' => generateRandomEmail(),
                        'limitIp' => 0,
                        'expiryTime' => calculateExpiryTimestamp($product['days_count']),
                        'enable' => true,
                        'tgId' => '',
                        'subId' => substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 16),
                        'comment' => '',
                        'reset' => 0
                    ];

                    // Add new client to settings
                    $new_settings = $original_settings;
                    $new_settings['clients'] = [$new_client];

                    // Set random port
                    $config_array['port'] = rand(10000, 65535);

                    // Set remark to the service name entered by the user
                    $config_array['remark'] = $service_name; // استفاده از نام سرویس وارد شده توسط کاربر

                    // Update main values
                    $config_array['up'] = '0';
                    $config_array['down'] = '0';
                    $config_array['total'] = convertGBToBytes($product['volume_gb']);
                    $config_array['enable'] = 'true';
                    $config_array['expiryTime'] = calculateExpiryTimestamp($product['days_count']);

                    // Merge settings while preserving structure
                    $merged_settings = preserveStructure($original_settings, $new_settings);
                    $config_array['settings'] = json_encode($merged_settings, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

                    // Preserve other settings with original structure
                    $config_array['streamSettings'] = $original_config_array['streamSettings'];
                    $config_array['sniffing'] = $original_config_array['sniffing'];
                    $config_array['allocate'] = $original_config_array['allocate'];

                    // Build final query string
                    $config_settings = http_build_query($config_array);

                    // Determine appropriate endpoint
                    $endpoint = '/panel/inbound/add';
                    $request_url = rtrim($server['url'], '/') . $endpoint;

                    // Log request details for debugging
                    error_log("Request URL: " . $request_url);
                    error_log("Request Data: " . $config_settings);

                    // Set request headers
                    $headers = [
                        "Cookie: " . $server['cookies'],
                        "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
                        "X-Requested-With: XMLHttpRequest"
                    ];

                    // Send request to server
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $request_url);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $config_settings);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    // Log CURL errors if any
                    if ($response === false) {
                        error_log("CURL Error: " . curl_error($ch));
                    }

                    curl_close($ch);

                    if ($http_code == 200) {
                        $response_data = json_decode($response, true);
                        if (isset($response_data['success']) && $response_data['success'] === true) {
                            // Extract server address
                            $server_url = $server['url'];
                            $serverAddress = parse_url($server_url, PHP_URL_HOST);

                            // Send to genratexui.php
                            $genratexui_response = sendToGenrateXUI($serverAddress, $response);
                            $message = processGenrateXUIResponse($genratexui_response, $chat_id);

                            // If tunnel_ip exists
                            $tunnel_response = null;
                            if (!empty($server['tunnel_ip'])) {
                                $tunnel_response = sendToGenrateXUI($server['tunnel_ip'], $response);
                                $message .= processGenrateXUIResponse($tunnel_response, $chat_id, true);
                            }

                            // Update configcount in users table
                            $stmt = $conn->prepare("UPDATE users SET configcount = configcount + 1 WHERE userid = ?");
                            $stmt->bind_param('i', $user_id);
                            $stmt->execute();

                            // Insert into usersconfig table
                            $stmt = $conn->prepare("INSERT INTO usersconfig (userid_c, config_c, configtunnel_c, name_config, email_config, server_id, config_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                            $stmt->bind_param('issssii', $user_id, $genratexui_response, $tunnel_response, $service_name, $new_client['email'], $server['id'], $product_id);
                            $stmt->execute();

                            // Add subscription link to message
                            $message = "\n\n🔄 *لینک سابسکریپشن:*\n`https://jorabin.ir/bot/pages/config_generator.php?email=" . $new_client['email'] . "`\n\n";
                            $message .= "🔗 *لینک کانفیگ عادی:*\n```\n" . $genratexui_response . "\n```";

                            // بعد از خرید موفق کانفیگ، لاگ را ثبت می‌کنیم
                            if ($response_data['success']) {
                                // لاگ کردن خرید کانفیگ
                                logConfigOperation(
                                    $conn,
                                    $user_id,
                                    $new_client['email'],
                                    'create',
                                    $server_id,
                                    [
                                        'name_config' => $service_name,
                                        'volume_gb' => $product['volume_gb'],
                                        'days_count' => $product['days_count'],
                                        'price' => $product['price']
                                    ],
                                    'success'
                                );
                            }
                        }
                    } else {
                        $message = "⚠️ خطا در ارسال درخواست به سرور.\n";
                        $message .= "📝 کد وضعیت: " . $http_code . "\n";
                        $message .= "📝 پاسخ سرور:\n" . $response;
                    }
                }

                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => 'بازگشت به جزئیات محصول', 'callback_data' => 'product_' . $product_id . '_server_' . $server_id]
                        ]
                    ]
                ];

                editMessage($chat_id, $message_id, $message, $keyboard);
            } else {
                editMessage($chat_id, $message_id, "⚠️ خطا در دریافت اطلاعات کانفیگ.");
            }
        } else {
            editMessage($chat_id, $message_id, "⚠️ هیچ کانفیگی برای این سرور یافت نشد.");
        }
    } catch (Exception $e) {
        error_log("Error in processPurchaseWithName: " . $e->getMessage());
        editMessage($chat_id, $message_id, "⚠️ خطایی در پردازش درخواست رخ داد.");
    }
}

/**
 * تبدیل گیگابایت به بایت با دقت بالا
 * 
 * @param int|float $gb مقدار گیگابایت
 * @return string مقدار بایت (بصورت عدد صحیح)
 */
function convertGBToBytes($gb) {
    global $logger;
    $bytes = $gb * 1024 * 1024 * 1024;
    $logger->debug("Converting GB to bytes", [
        'gb' => $gb,
        'bytes' => $bytes
    ]);
    return $bytes;
}

/**
 * تبدیل تاریخ به timestamp میلی‌ثانیه‌ای
 * 
 * @param int $days تعداد روزها
 * @return string timestamp به میلی‌ثانیه
 */
function calculateExpiryTimestamp($days) {
    global $logger;
    // Calculate timestamp in seconds then convert to milliseconds
    $timestamp = (time() + ($days * 24 * 60 * 60)) * 1000;
    $logger->debug("Calculating expiry timestamp", [
        'days' => $days,
        'timestamp' => $timestamp
    ]);
    return $timestamp;
}
/**
 * تولید یک UUID تصادفی
 */
function generateUUID() {
    global $logger;
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    $logger->debug("Generated UUID", ['uuid' => $uuid]);
    return $uuid;
}

/**
 * تولید یک ایمیل تصادفی
 */
function generateRandomEmail() {
    global $logger;
    $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $email = '';
    for ($i = 0; $i < 8; $i++) {
        $email .= $characters[rand(0, strlen($characters) - 1)];
    }
    $logger->debug("Generated random email", ['email' => $email]);
    return $email;
}

// تابع کمکی برای ارسال به genratexui.php
function sendToGenrateXUI($serverAddress, $jsonData) {
    global $logger;
    $logger->info("Sending data to generate XUI", [
        'server_address' => $serverAddress
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://jorabin.ir/bot/pages/tel/modules/genratexui.php');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'serverAddress' => $serverAddress,
        'jsonData' => $jsonData
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// تابع کمکی برای پردازش پاسخ genratexui.php
function processGenrateXUIResponse($response, $chat_id, $isTunnel = false) {
    global $logger, $token;
    $logger->info("Processing XUI response", [
        'is_tunnel' => $isTunnel,
        'chat_id' => $chat_id
    ]);

    // پردازش ابتدایی پاسخ
    if (strpos($response, 'Generated Link:') === false) {
        // اگر پاسخ معتبر نباشد، خطا را برمی‌گردانیم
        $error_message = "\n\n⚠️ خطا در پردازش اطلاعات توسط genratexui.php" . ($isTunnel ? " (با tunnel_ip)" : "") . ":\n" . $response;
        return $error_message;
    }

    // استخراج لینک
    $generated_link = trim(str_replace('Generated Link:', '', $response));
    
    if (!$isTunnel) {
        // این کانفیگ عادی است، متن را آماده و ذخیره می‌کنیم
        $message = "\n\n✨ *متشکریم از خرید شما*\n";
        $message .= "📛 نام سرویس: " . $GLOBALS['service_name'] . "\n";
        $message .= "📧 ایمیل: " . $GLOBALS['new_email'] . "\n";
        $message .= "💾 حجم: " . $GLOBALS['product']['volume_gb'] . " گیگابایت\n";
        $message .= "⏳ مدت زمان: " . $GLOBALS['product']['days_count'] . " روز\n\n";
        $message .= "🔗 *لینک کانفیگ عادی:*\n```\n" . $generated_link . "\n```";
        
        // ذخیره پیام خرید در متغیر سراسری
        $GLOBALS['purchase_message'] = $message;
        $GLOBALS['normal_config'] = $generated_link;

        return $message;
    } else {
        // برای کانفیگ تونل شده
        $tunnel_config = $generated_link;
        $GLOBALS['tunnel_config'] = $tunnel_config;
        
        // ترکیب پیام اصلی با اطلاعات تونل
        $full_message = $GLOBALS['purchase_message'] . "\n\n";
        $full_message .= "🔗 *لینک کانفیگ تونل شده:*\n```\n" . $tunnel_config . "\n```";
        
        // اضافه کردن لینک سابسکریپشن به پیام
        $full_message .= "\n\n🔄 *لینک سابسکریپشن:*\n`https://jorabin.ir/bot/pages/config_generator.php?email=" . $GLOBALS['new_email'] . "`";
        
        // دکمه‌های مورد نیاز - تغییر به بازگشت به جزئیات محصول
        $keyboard = [
            'inline_keyboard' => [
                [['text' => 'بازگشت به جزئیات محصول', 'callback_data' => 'product_' . $GLOBALS['product']['id'] . '_server_' . $GLOBALS['server_id']]]
            ]
        ];
        
        // ایجاد QR کد و ارسال همه چیز در یک پیام واحد
        $qr_success = createAndSendQRWithMessage($chat_id, $tunnel_config, $full_message, $keyboard);
        
        if ($qr_success) {
            // اگر ارسال موفق بود، هیچ متنی برنمی‌گردانیم تا دوباره ارسال نشود
            return "";
        } else {
            // اگر ارسال با QR ناموفق بود، متن را برمی‌گردانیم تا به روش عادی ارسال شود
            $logger->error("Failed to send QR image, returning text only");
            return "\n\n🔗 *لینک کانفیگ تونل شده:*\n```\n" . $tunnel_config . "\n```";
        }
    }
}

/**
 * ایجاد QR کد و ارسال آن همراه با متن در یک پیام
 */
function createAndSendQRWithMessage($chat_id, $config_link, $caption, $keyboard = null) {
    global $logger, $token;
    
    $logger->info("Creating and sending QR with message", [
        'chat_id' => $chat_id,
        'config_length' => strlen($config_link)
    ]);
    
    // ایجاد دایرکتوری برای ذخیره موقت تصاویر QR
    $upload_dir = __DIR__ . "/../../uploads/qrcodes/";
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // ایجاد نام فایل منحصر به فرد
    $file_name = md5($config_link . time()) . '.png';
    $file_path = $upload_dir . $file_name;
    $qr_created = false;
    
    // روش 1: استفاده از API کد QR خارجی و ذخیره محلی
    try {
        // لیست سرویس‌های مختلف QR برای تلاش
        $qr_services = [
            "https://quickchart.io/qr?text=" . urlencode($config_link) . "&size=300&dark=000000",
            "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($config_link),
            "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($config_link)
        ];
        
        foreach ($qr_services as $service_url) {
            $logger->info("Trying QR service", ['service' => $service_url]);
            
            $ch = curl_init($service_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            
            $qr_data = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code == 200 && $qr_data && strlen($qr_data) > 100) {
                file_put_contents($file_path, $qr_data);
                
                if (file_exists($file_path) && filesize($file_path) > 100) {
                    $qr_created = true;
                    $logger->info("QR image created successfully", [
                        'service' => $service_url,
                        'file_size' => filesize($file_path)
                    ]);
                    break;
                }
            }
        }
    } catch (Exception $e) {
        $logger->error("Error creating QR image from services", [
            'error' => $e->getMessage()
        ]);
    }
    
    // اگر QR ایجاد شد، آن را ارسال می‌کنیم
    if ($qr_created) {
        $result = false;
        
        // تلاش برای ارسال عکس از فایل محلی
        try {
            $logger->info("Sending QR image as photo", ['file_path' => $file_path]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot$token/sendPhoto");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'chat_id' => $chat_id,
                'photo' => new CURLFile($file_path),
                'caption' => $caption,
                'parse_mode' => 'Markdown',
                'reply_markup' => $keyboard ? json_encode($keyboard) : null
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $response_data = json_decode($response, true);
            
            if ($http_code == 200 && isset($response_data['ok']) && $response_data['ok'] === true) {
                $logger->info("QR image sent successfully", [
                    'message_id' => $response_data['result']['message_id'] ?? 'unknown'
                ]);
                $result = true;
            } else {
                throw new Exception("HTTP Error: " . $http_code . ", Response: " . ($response_data['description'] ?? 'Unknown error'));
            }
        } catch (Exception $e) {
            $logger->error("Error sending QR image", [
                'error' => $e->getMessage()
            ]);
        }
        
        // پاکسازی فایل موقت
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
        
        return $result;
    }
    
    // اگر به اینجا رسیدیم، یعنی QR ایجاد نشده یا ارسال آن ناموفق بوده
    $logger->error("Failed to create or send QR image");
    return false;
}

function handleProductSelection($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];

    $logger->info("Handling product selection", [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);

    $data = $callback_query['data'];
    $parts = explode('_', $data);
    $product_id = $parts[1];
    $server_id = $parts[3];

    // دریافت اطلاعات محصول و سرور
    $product = getProductDetails($conn, $product_id);
    $server = getServerDetails($conn, $server_id);
    
    if (!$product || !$server) {
        editMessage($chat_id, $message_id, "خطا در دریافت اطلاعات محصول.");
        return;
    }

    // ساخت متن پیام
    $message = "📦 نام محصول: " . $product['product_name'] . "\n";
    $message .= "💰 قیمت: " . number_format($product['price']) . " تومان\n";
    $message .= "📝 توضیحات: " . $product['description'] . "\n\n";
    $message .= "📡 سرور: " . $server['name'] . "\n";
    $message .= "⚡️ ظرفیت: " . $server['capacity'] . "\n";
    
    // ساخت دکمه‌های شیشه‌ای
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🛍 خرید این سرویس', 'callback_data' => 'buy_product_' . $product_id . '_server_' . $server_id]
            ],
            [
                ['text' => 'بازگشت به لیست محصولات', 'callback_data' => 'server_' . $server_id]
            ]
        ]
    ];

    // به‌روزرسانی پیام با جزئیات محصول
    editMessage($chat_id, $message_id, $message, $keyboard);
}

/**
 * دریافت اطلاعات سرورها از دیتابیس
 */
function getServersFromDatabase($conn) {
    global $logger;
    $logger->info("Getting servers from database");

    try {
        $query = "SELECT * FROM servers WHERE 1";
        $result = $conn->query($query);
        $servers = [];

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $servers[] = $row;
            }
        }

        return $servers;
    } catch (Exception $e) {
        error_log("Error in getServersFromDatabase: " . $e->getMessage());
        return [];
    }
}

/**
 * دریافت محصولات مرتبط با سرور
 */
function getProductsByServer($conn, $server_id) {
    global $logger;
    $logger->info("Getting products by server", [
        'server_id' => $server_id
    ]);

    try {
        $stmt = $conn->prepare("SELECT p.*, cs.config_id FROM products p 
                               INNER JOIN config_servers cs ON FIND_IN_SET(cs.config_id, p.config_ids) 
                               WHERE cs.server_id = ?");
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $products = [];

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                // اضافه کردن قیمت فرمت شده به نام محصول
                $row['product_name'] = $row['product_name'] . ' (' . number_format($row['price']) . ' تومان)';
                $products[] = $row;
            }
        }

        return $products;
    } catch (Exception $e) {
        error_log("Error in getProductsByServer: " . $e->getMessage());
        return [];
    }
}

/**
 * دریافت جزئیات محصول
 */
function getProductDetails($conn, $product_id) {
    global $logger;
    $logger->info("Getting product details", [
        'product_id' => $product_id
    ]);

    try {
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    } catch (Exception $e) {
        error_log("Error in getProductDetails: " . $e->getMessage());
        return null;
    }
}

/**
 * دریافت جزئیات سرور
 */
function getServerDetails($conn, $server_id) {
    global $logger;
    $logger->info("Getting server details", [
        'server_id' => $server_id
    ]);

    try {
        $stmt = $conn->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    } catch (Exception $e) {
        error_log("Error in getServerDetails: " . $e->getMessage());
        return null;
    }
}

/**
 * پردازش نام سرویس وارد شده توسط کاربر
 */
function processServiceName($message, $conn, $temp_data) {
    global $logger;
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $text = $message['text'];
    
    $logger->info("Processing service name", [
        'chat_id' => $chat_id,
        'text' => $text
    ]);

    // پیدا کردن message_id آخرین پیام ربات
    $stmt = $conn->prepare("SELECT message_id FROM bot_messages WHERE chat_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $chat_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $last_message = $result->fetch_assoc();
    $message_id = $last_message ? $last_message['message_id'] : null;
    
    // اعتبارسنجی نام سرویس
    if (strlen($text) > 50) {
        editMessage($chat_id, $message_id, "⚠️ نام سرویس نمی‌تواند بیشتر از 50 کاراکتر باشد. لطفاً نام کوتاه‌تری وارد کنید.");
        return;
    }
    
    if (strlen($text) < 3) {
        editMessage($chat_id, $message_id, "⚠️ نام سرویس باید حداقل 3 کاراکتر باشد. لطفاً نام دیگری وارد کنید.");
        return;
    }

    try {
        // برگرداندن داده‌های ذخیره شده
        $data = json_decode($temp_data, true);
        if (!$data) {
            throw new Exception("خطا در بازیابی اطلاعات");
        }

        $product_id = $data['product_id'];
        $server_id = $data['server_id'];

        // پاک کردن وضعیت کاربر
        $stmt = $conn->prepare("DELETE FROM user_states WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();

        // ذخیره نام سرویس در متغیر global
        $GLOBALS['service_name'] = $text;

        // نمایش پیام در حال پردازش
        $processing_message = sendMessage($chat_id, "🔄 در حال پردازش درخواست شما...");
        
        if (!$processing_message) {
            throw new Exception("خطا در ارسال پیام پردازش");
        }
        
        $processing_message_data = json_decode($processing_message, true);
        if (!isset($processing_message_data['result']['message_id'])) {
            throw new Exception("خطا در دریافت شناسه پیام");
        }
        
        $processing_message_id = $processing_message_data['result']['message_id'];

        // ساخت callback query جدید
        $callback_query = [
            'data' => 'buy_product_' . $product_id . '_server_' . $server_id,
            'message' => [
                'chat' => ['id' => $chat_id],
                'message_id' => $processing_message_id
            ],
            'from' => ['id' => $user_id]
        ];

        // ادامه روند خرید
        handleBuyProduct($callback_query, $conn);

    } catch (Exception $e) {
        error_log("Error in processServiceName: " . $e->getMessage());
        editMessage($chat_id, $message_id, "⚠️ خطایی در پردازش نام سرویس رخ داد. لطفاً دوباره تلاش کنید.");
    }
}

function sendPhoto($chat_id, $photo, $caption = '', $keyboard = null) {
    global $token, $logger;
    $logger->info("Sending photo", [
        'chat_id' => $chat_id,
        'caption_length' => strlen($caption),
        'photo_type' => is_string($photo) ? (strpos($photo, 'http') === 0 ? 'URL' : (file_exists($photo) ? 'LOCAL_FILE' : 'FILE_ID')) : 'FILE'
    ]);

    // تنظیم داده‌ها براساس نوع عکس
    if (is_string($photo)) {
        if (strpos($photo, 'http') === 0) {
            // اگر URL است، ابتدا دانلود کنیم
            try {
                $temp_file = tempnam(sys_get_temp_dir(), 'tg_qr_');
                
                // دانلود فایل با curl
                $ch = curl_init($photo);
                $fp = fopen($temp_file, 'wb');
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
                curl_exec($ch);
                $curl_error = curl_error($ch);
                curl_close($ch);
                fclose($fp);
                
                if (empty($curl_error) && file_exists($temp_file) && filesize($temp_file) > 0) {
                    $photo = $temp_file;
                    $logger->info("URL downloaded to temporary file", [
                        'url' => $photo,
                        'temp_file' => $temp_file,
                        'file_size' => filesize($temp_file)
                    ]);
                } else {
                    throw new Exception("Failed to download URL: " . $curl_error);
                }
            } catch (Exception $e) {
                $logger->error("Error downloading URL to temp file", [
                    'error' => $e->getMessage()
                ]);
                // ادامه با URL اصلی
            }
        }
        
        if (file_exists($photo)) {
            // اگر مسیر فایل محلی است
            $data = [
                'chat_id' => $chat_id,
                'caption' => $caption,
                'parse_mode' => 'Markdown'
            ];
            $file = new CURLFile($photo);
            $data['photo'] = $file;
            
            $logger->info("Using local file for photo", [
                'file_path' => $photo,
                'file_size' => filesize($photo)
            ]);
        } else {
            // احتمالاً file_id یا URL که دانلود نشده
            $data = [
                'chat_id' => $chat_id,
                'photo' => $photo,
                'caption' => $caption,
                'parse_mode' => 'Markdown'
            ];
        }
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
    $success = false;
    $last_error = '';
    
    while ($retryCount > 0 && !$success) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot$token/sendPhoto");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($response === false) {
                $last_error = "CURL Error: " . $error . " (Code: " . $errno . ")";
                throw new Exception($last_error);
            }

            $result = json_decode($response, true);
            
            if ($http_code !== 200 || !isset($result['ok']) || $result['ok'] !== true) {
                $errorMsg = isset($result['description']) ? $result['description'] : 'Unknown HTTP error';
                $last_error = "HTTP Error: " . $http_code . ", Response: " . $errorMsg;
                throw new Exception($last_error);
            }

            $logger->info("Photo sent successfully", [
                'chat_id' => $chat_id,
                'message_id' => $result['result']['message_id'] ?? 'unknown'
            ]);
            
            $success = true;

        } catch (Exception $e) {
            $retryCount--;
            $last_error = $e->getMessage();
            $logger->error("Error sending photo (attempt " . (3 - $retryCount) . "/3)", [
                'error' => $last_error,
                'chat_id' => $chat_id
            ]);
            
            if ($retryCount > 0) {
                sleep(1); // Wait 1 second before retry
            }
        }
    }
    
    // پاکسازی فایل‌های موقت
    if (isset($temp_file) && file_exists($temp_file)) {
        @unlink($temp_file);
    }
    
    if (!$success) {
        // اگر ارسال با عکس شکست خورد، سعی می‌کنیم متن را بدون عکس بفرستیم
        $logger->error("Failed to send photo after 3 attempts, trying to send text only", [
            'last_error' => $last_error
        ]);
        
        try {
            $text_data = [
                'chat_id' => $chat_id,
                'text' => $caption,
                'parse_mode' => 'Markdown'
            ];
            
            if ($keyboard) {
                $text_data['reply_markup'] = json_encode($keyboard);
            }
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot$token/sendMessage");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $text_data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $text_response = curl_exec($ch);
            curl_close($ch);
            
            $text_result = json_decode($text_response, true);
            if (isset($text_result['ok']) && $text_result['ok'] === true) {
                $logger->info("Sent text message as fallback", [
                    'chat_id' => $chat_id
                ]);
                return true; // حداقل توانستیم متن را ارسال کنیم
            }
        } catch (Exception $e) {
            $logger->error("Failed to send text fallback message", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    return $success;
}

/**
 * لاگ کردن عملیات‌های مربوط به کانفیگ
 * 
 * @param mysqli $conn اتصال به دیتابیس
 * @param int $user_id شناسه کاربر
 * @param string $email ایمیل کانفیگ
 * @param string $operation نوع عملیات (create, update, delete)
 * @param int $server_id شناسه سرور
 * @param array $details جزئیات عملیات
 * @param string $status وضعیت عملیات (success, failed)
 */
function logConfigOperation($conn, $user_id, $email, $operation, $server_id, $details, $status) {
    global $logger;
    
    $logger->info("Logging config operation", [
        'user_id' => $user_id,
        'email' => $email,
        'operation' => $operation,
        'server_id' => $server_id,
        'status' => $status
    ]);

    try {
        $stmt = $conn->prepare("INSERT INTO config_logs (user_id, email, operation, server_id, details, status, created_at) 
                               VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $details_json = json_encode($details);
        $stmt->bind_param('ississ', $user_id, $email, $operation, $server_id, $details_json, $status);
        $stmt->execute();
    } catch (Exception $e) {
        $logger->error("Error logging config operation", [
            'error' => $e->getMessage()
        ]);
    }
}