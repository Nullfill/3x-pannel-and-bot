<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../utils/Logger.php';

$logger = Logger::getInstance();

// فعال کردن نمایش خطاها
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// دریافت پارامترها
$url = $_POST['url'] ?? '';
$email = $_POST['email'] ?? '';
$id = $_POST['id'] ?? '';  // اضافه کردن پارامتر id
$cookie = $_POST['cookie'] ?? '';

// لاگ کردن پارامترهای ورودی
$logger->info("Received parameters", [
    'url' => $url,
    'email' => $email,
    'id' => $id,
    'cookie_length' => strlen($cookie)
]);

// بررسی پارامترهای ورودی
if (empty($url)) {
    $logger->error("URL is empty");
    die(json_encode([
        'success' => false,
        'msg' => 'آدرس URL خالی است'
    ]));
}

// بررسی وجود حداقل یکی از email یا id
if (empty($email) && empty($id)) {
    $logger->error("Both Email and ID are empty");
    die(json_encode([
        'success' => false,
        'msg' => 'حداقل یکی از ایمیل یا شناسه باید وارد شود'
    ]));
}

if (empty($cookie)) {
    $logger->error("Cookie is empty");
    die(json_encode([
        'success' => false,
        'msg' => 'کوکی خالی است'
    ]));
}

try {
    $logger->info("Step 1: Validating URL");
    
    // اطمینان از اینکه URL با /panel/inbound/list تمام می‌شود
    $base_url = rtrim($url, '/');
    $api_url = $base_url;
    
    if (substr($api_url, -strlen('/panel/inbound/list')) !== '/panel/inbound/list') {
        $api_url .= '/panel/inbound/list';
    }

    // بررسی معتبر بودن URL
    if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
        throw new Exception("آدرس URL نامعتبر است: $api_url");
    }

    // استخراج دامنه و پروتکل برای استفاده در هدرها
    $parsed_url = parse_url($api_url);
    $origin = $parsed_url['scheme'] . '://' . $parsed_url['host'];
    if (isset($parsed_url['port'])) {
        $origin .= ':' . $parsed_url['port'];
    }
    
    $referer = $base_url . '/panel/inbounds';

    $logger->info("Request details", [
        'final_url' => $api_url,
        'origin' => $origin,
        'referer' => $referer
    ]);

    $logger->info("Step 2: Setting up cURL request");
    
    // ارسال درخواست به پنل
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIE => $cookie,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => '',
        CURLOPT_ENCODING => 'gzip, deflate',
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:136.0) Gecko/20100101 Firefox/136.0',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.5',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With: XMLHttpRequest',
            'Origin: ' . $origin,
            'Connection: keep-alive',
            'Referer: ' . $referer,
            'Content-Length: 0'
        ]
    ]);

    $logger->info("Step 3: Executing cURL request");
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $logger->info("Response details", [
        'http_code' => $http_code,
        'curl_error' => $curl_error
    ]);
    
    // بررسی خطاهای cURL
    if ($curl_error) {
        throw new Exception("خطا در ارتباط با سرور: $curl_error");
    }

    // بررسی کد پاسخ HTTP
    if ($http_code !== 200) {
        $logger->error("HTTP Error", [
            'http_code' => $http_code,
            'response' => substr($response, 0, 1000)
        ]);
        throw new Exception("خطای HTTP: $http_code - لطفاً URL و کوکی را بررسی کنید");
    }

    $logger->info("Step 4: Decoding response JSON");
    
    // بررسی پاسخ JSON
    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $logger->error("JSON Error", [
            'error' => json_last_error_msg(),
            'raw_response' => substr($response, 0, 1000)
        ]);
        throw new Exception("خطا در پردازش پاسخ سرور: " . json_last_error_msg() . 
                          "\n\nپاسخ خام سرور: " . substr($response, 0, 500));
    }
    
    $logger->info("Step 5: Validating response structure");
    
    // بررسی ساختار پاسخ
    if (!isset($data['success']) || $data['success'] !== true) {
        $message = isset($data['msg']) ? $data['msg'] : 'پاسخ نامعتبر از سرور';
        $logger->error("Invalid response structure", ['data' => $data]);
        throw new Exception($message);
    }
    
    if (!isset($data['obj']) || !is_array($data['obj'])) {
        $logger->error("Missing or invalid 'obj' field in response");
        throw new Exception("داده‌های کانفیگ در پاسخ سرور یافت نشد");
    }

    $logger->info("Step 6: Searching for client by email or id");
    
    // جستجوی کانفیگ با ایمیل یا ID مورد نظر
    $config_data = null;
    
    foreach ($data['obj'] as $inbound) {
        $logger->debug("Checking inbound", ['id' => $inbound['id'] ?? 'unknown']);
        
        // بررسی وجود تنظیمات
        if (!isset($inbound['settings'])) {
            $logger->debug("No settings found in this inbound");
            continue;
        }
        
        // تلاش برای decode تنظیمات
        $settings = json_decode($inbound['settings'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logger->error("Error decoding settings JSON", ['error' => json_last_error_msg()]);
            continue;
        }
        
        // بررسی وجود فیلد clients
        if (!isset($settings['clients']) || !is_array($settings['clients'])) {
            $logger->debug("No clients found in settings");
            continue;
        }
        
        $logger->debug("Found clients in inbound", ['count' => count($settings['clients'])]);
        
        // جستجو در بین کلاینت‌ها
        foreach ($settings['clients'] as $index => $client) {
            $matched = false;
            
            // بررسی مطابقت ایمیل اگر ایمیل ارائه شده باشد
            if (!empty($email) && isset($client['email']) && $client['email'] === $email) {
                $logger->info("Found matching client email", ['email' => $email]);
                $matched = true;
            }
            
            // بررسی مطابقت ID اگر ID ارائه شده باشد
            if (!$matched && !empty($id)) {
                // بررسی ID در فیلدهای مختلف احتمالی
                if (
                    (isset($client['id']) && $client['id'] === $id) || 
                    (isset($client['uuid']) && $client['uuid'] === $id)
                ) {
                    $logger->info("Found matching client id", ['id' => $id]);
                    $matched = true;
                }
            }
            
            if ($matched) {
                // استخراج اطلاعات کلاینت
                $config_data = [];
                
                // اضافه کردن ایمیل اگر وجود داشته باشد
                if (isset($client['email'])) {
                    $config_data['email'] = $client['email'];
                } else {
                    $config_data['email'] = 'unknown';
                }
                
                // اضافه کردن id کاربر
                if (isset($client['id'])) {
                    $config_data['id'] = $client['id'];
                } else if (isset($client['uuid'])) {
                    $config_data['id'] = $client['uuid'];
                } else {
                    $config_data['id'] = "unknown";
                }
                
                // تنظیم زمان انقضا
                if (isset($client['expiryTime'])) {
                    $expiry_timestamp = is_numeric($client['expiryTime']) && $client['expiryTime'] > time() * 100 
                        ? $client['expiryTime'] / 1000
                        : $client['expiryTime'];
                    
                    $config_data['expiryTime'] = $expiry_timestamp;
                    
                    if ($expiry_timestamp > time()) {
                        $remaining_days = ceil(($expiry_timestamp - time()) / (24 * 60 * 60));
                        $config_data['remainingDays'] = $remaining_days;
                    } else {
                        $config_data['remainingDays'] = 0;
                    }
                } else {
                    $config_data['expiryTime'] = 0;
                    $config_data['remainingDays'] = 0;
                }
                
                if (isset($client['totalGB'])) {
                    $config_data['totalGB'] = $client['totalGB'];
                } else {
                    $config_data['totalGB'] = 0;
                }
                
                if (isset($client['up']) && isset($client['down'])) {
                    $up = intval($client['up']);
                    $down = intval($client['down']);
                    
                    $config_data['up'] = $up;
                    $config_data['down'] = $down;
                    $config_data['total'] = $up + $down;
                    
                    $total_usage_gb = ($up + $down) / (1024 * 1024 * 1024);
                    $config_data['usedTrafficGB'] = round($total_usage_gb, 2);
                    $config_data['totalUsage'] = round($total_usage_gb, 2) . 'GB';
                } 
                else if (isset($inbound['clientStats']) && is_array($inbound['clientStats'])) {
                    $client_email = $config_data['email'];
                    $client_id = $config_data['id'];
                    
                    foreach ($inbound['clientStats'] as $stat) {
                        // بررسی تطابق با email یا id
                        if (
                            (isset($stat['email']) && $stat['email'] === $client_email) ||
                            (isset($stat['id']) && $stat['id'] === $client_id)
                        ) {
                            $up = isset($stat['up']) ? intval($stat['up']) : 0;
                            $down = isset($stat['down']) ? intval($stat['down']) : 0;
                            
                            $config_data['up'] = $up;
                            $config_data['down'] = $down;
                            $config_data['total'] = $up + $down;
                            
                            $total_usage_gb = ($up + $down) / (1024 * 1024 * 1024);
                            $config_data['usedTrafficGB'] = round($total_usage_gb, 2);
                            $config_data['totalUsage'] = round($total_usage_gb, 2) . 'GB';
                            
                            // اضافه کردن id از clientStats اگر موجود باشد و قبلاً unknown بوده
                            if (isset($stat['id']) && $config_data['id'] === "unknown") {
                                $config_data['id'] = $stat['id'];
                            }
                            break;
                        }
                    }
                }
                
                if (!isset($config_data['up'])) {
                    $config_data['up'] = 0;
                    $config_data['down'] = 0;
                    $config_data['total'] = 0;
                    $config_data['usedTrafficGB'] = 0;
                    $config_data['totalUsage'] = '0GB';
                }
                
                if (isset($client['enable'])) {
                    $config_data['enable'] = (bool)$client['enable'];
                }
                
                $logger->info("Extracted client data", ['config_data' => $config_data]);
                break 2;
            }
        }
        
        // اگر کاربر با ID مورد نظر در clientStats پیدا نشد، جستجو در clientStats
        if (!$config_data && !empty($id) && isset($inbound['clientStats']) && is_array($inbound['clientStats'])) {
            foreach ($inbound['clientStats'] as $stat) {
                if (isset($stat['id']) && $stat['id'] === $id) {
                    $logger->info("Found matching client id in clientStats", ['id' => $id]);
                    
                    $config_data = [
                        'id' => $stat['id']
                    ];
                    
                    // افزودن ایمیل اگر موجود باشد
                    if (isset($stat['email'])) {
                        $config_data['email'] = $stat['email'];
                    } else {
                        $config_data['email'] = 'unknown';
                    }
                    
                    // افزودن آمار ترافیک
                    $up = isset($stat['up']) ? intval($stat['up']) : 0;
                    $down = isset($stat['down']) ? intval($stat['down']) : 0;
                    
                    $config_data['up'] = $up;
                    $config_data['down'] = $down;
                    $config_data['total'] = $up + $down;
                    
                    $total_usage_gb = ($up + $down) / (1024 * 1024 * 1024);
                    $config_data['usedTrafficGB'] = round($total_usage_gb, 2);
                    $config_data['totalUsage'] = round($total_usage_gb, 2) . 'GB';
                    
                    // مقادیر پیش‌فرض برای فیلدهای دیگر
                    $config_data['expiryTime'] = 0;
                    $config_data['remainingDays'] = 0;
                    $config_data['totalGB'] = 0;
                    $config_data['enable'] = true;
                    
                    $logger->info("Extracted client data from clientStats", ['config_data' => $config_data]);
                    break 2;
                }
            }
        }
    }

    $logger->info("Step 7: Finalizing response");
    
    // بررسی نتیجه جستجو
    if (!$config_data) {
        $search_param = !empty($email) ? "ایمیل $email" : "شناسه $id";
        throw new Exception("کانفیگ با $search_param یافت نشد. لطفاً مقادیر ورودی را بررسی کنید");
    }

    // ارسال پاسخ موفق
    echo json_encode([
        'success' => true,
        'data' => $config_data
    ]);

} catch (Exception $e) {
    // لاگ کردن خطا
    $logger->error("Error occurred", [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // ارسال پاسخ خطا
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'msg' => $e->getMessage()
    ]);
}
?>