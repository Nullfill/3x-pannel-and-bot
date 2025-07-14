<?php
require_once __DIR__ . '/../utils/Logger.php';

$logger = Logger::getInstance();

function handleModule($callback_query, $conn) {
    global $logger;
    $chat_id = $callback_query['message']['chat']['id'];
    $message_id = $callback_query['message']['message_id'];
    $user_id = $callback_query['from']['id'];

    $logger->info("Handling search service module", [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ]);

    // حذف state قبلی
    deletePreviousUserState($conn, $user_id);

    $message = "🔍 لطفاً کانفیگ سرویس خود را ارسال کنید.\n\n";
    $message .= "⚠️ توجه: کانفیگ باید به یکی از فرمت‌های زیر باشد:\n";
    $message .= "- VLESS\n";
    $message .= "- VMESS";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']]
        ]
    ];

    editMessage($chat_id, $message_id, $message, $keyboard);

    $stmt = $conn->prepare("INSERT INTO user_states (user_id, state, temp_data) VALUES (?, ?, ?)");
    $state = 'waiting_config';
    $temp_data = json_encode([]);
    $stmt->bind_param('iss', $user_id, $state, $temp_data);
    $stmt->execute();
}

function processConfig($message, $conn) {
    global $logger;
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $text = $message['text'];

    $logger->info("Processing config", [
        'chat_id' => $chat_id,
        'text' => $text
    ]);

    // حذف state قبلی
    deletePreviousUserState($conn, $user_id);

    // استخراج ID از کانفیگ
    $id = null;
    if (strpos($text, 'vless://') === 0) {
        // استخراج ID از VLESS
        $parts = explode('@', $text);
        if (count($parts) > 1) {
            $id = $parts[0];
            $id = str_replace('vless://', '', $id);
        }
    } elseif (strpos($text, 'vmess://') === 0) {
        // استخراج ID از VMESS
        $base64 = str_replace('vmess://', '', $text);
        $json = base64_decode($base64);
        if ($json) {
            $data = json_decode($json, true);
            if (isset($data['id'])) {
                $id = $data['id'];
            }
        }
    }

    if (!$id) {
        sendMessage($chat_id, "❌ فرمت کانفیگ نامعتبر است. لطفاً یک کانفیگ معتبر ارسال کنید.");
        return;
    }

    // جستجوی سرور مربوطه
    $stmt = $conn->prepare("SELECT url, cookies FROM servers WHERE id IN (SELECT id FROM products)");
    $stmt->execute();
    $result = $stmt->get_result();
    $servers = [];

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $servers[] = $row;
        }
    }

    if (empty($servers)) {
        sendMessage($chat_id, "❌ خطا در دریافت اطلاعات سرورها.");
        return;
    }

    // ارسال درخواست به هر سرور
    $found = false;
    foreach ($servers as $server) {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://jorabin.ir/bot/pages/tel/modules/getdatapanel.php',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => array(
                'url' => $server['url'],
                'id' => $id,
                'cookie' => $server['cookies']
            ),
        ));

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code === 200) {
            $data = json_decode($response, true);
            if ($data && isset($data['success']) && $data['success'] && isset($data['data'])) {
                $client = $data['data'];
                $found = true;
                $message = "✅ اطلاعات سرویس شما:\n\n";
                $message .= "📧 ایمیل: " . $client['email'] . "\n";
                $message .= "🆔 ID: " . $client['id'] . "\n";
                $message .= "⏳ روزهای باقیمانده: " . $client['remainingDays'] . "\n";
                $message .= "💾 حجم کل: " . formatBytes($client['totalGB']) . "\n";
                $message .= "⬆️ آپلود: " . formatBytes($client['up']) . "\n";
                $message .= "⬇️ دانلود: " . formatBytes($client['down']) . "\n";
                $message .= "📊 کل مصرف: " . $client['totalUsage'] . "\n";
                $message .= "🔌 وضعیت: " . ($client['enable'] ? "✅ فعال" : "❌ غیرفعال");

                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']]
                    ]
                ];

                sendMessage($chat_id, $message, $keyboard);
                break;
            }
        }
    }

    if (!$found) {
        sendMessage($chat_id, "❌ سرویس با این مشخصات یافت نشد.");
    }
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}