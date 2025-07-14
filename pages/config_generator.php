<?php
// تنظیمات نمایش خطا
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// بررسی وجود فایل Logger
$logger_file = __DIR__ . '/tel/utils/Logger.php';
if (!file_exists($logger_file)) {
    echo base64_encode("Logger file not found: " . $logger_file);
    exit;
}

require_once $logger_file;

try {
    $logger = Logger::getInstance();
} catch (Exception $e) {
    echo base64_encode("Error initializing logger: " . $e->getMessage());
    exit;
}

// Get email from GET parameter
$email = $_GET['email'] ?? '';

if (empty($email)) {
    echo base64_encode("Email parameter is required");
    exit;
}

try {
    // بررسی وجود فایل دیتابیس
    $db_file = __DIR__ . '/tel/utils/db.php';
    if (!file_exists($db_file)) {
        throw new Exception('Database file not found: ' . $db_file);
    }
    
    // Connect to database
    require_once $db_file;
    $conn = getDBConnection();
    
    if (!$conn) {
        throw new Exception('Failed to connect to database');
    }

    // Get config details from database
    $stmt = $conn->prepare("SELECT uc.*, s.url, s.cookies, s.name as server_name 
                           FROM usersconfig uc 
                           JOIN servers s ON uc.server_id = s.id 
                           WHERE uc.email_config = ?");
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . $conn->error);
    }
    
    $stmt->bind_param('s', $email);
    
    if (!$stmt->execute()) {
        throw new Exception('Database execute error: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if (!$result || $result->num_rows === 0) {
        throw new Exception("No configuration found for this email: $email");
    }

    $config = $result->fetch_assoc();
    
    // بررسی وجود فیلدهای اصلی در نتیجه
    if (!isset($config['url']) || !isset($config['cookies']) || !isset($config['config_c'])) {
        throw new Exception('Missing required fields in config data');
    }
    
    // Get current config details from panel
    $panel_url = rtrim($config['url'], '/') . '/panel/inbound/list';
    $logger->info("Connecting to panel URL", ['url' => $panel_url]);
    
    // Initialize cURL
    $ch = curl_init();
    
    if (!$ch) {
        throw new Exception('Failed to initialize cURL');
    }
    
    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_URL => $panel_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIE => $config['cookies'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:136.0) Gecko/20100101 Firefox/136.0',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.5',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With: XMLHttpRequest',
            'Origin: ' . rtrim($config['url'], '/'),
            'Connection: keep-alive',
            'Referer: ' . rtrim($config['url'], '/') . '/panel/inbounds',
            'Content-Length: 0'
        ]
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        throw new Exception("cURL error: $curl_error");
    }

    if ($http_code !== 200) {
        throw new Exception("Error connecting to server. HTTP code: $http_code, Response: " . substr($response, 0, 200));
    }

    if (empty($response)) {
        throw new Exception("Empty response from server");
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON decode error: " . json_last_error_msg() . ", Response: " . substr($response, 0, 200));
    }
    
    if (!$data || !isset($data['obj'])) {
        throw new Exception("Invalid response from server: " . substr($response, 0, 200));
    }

    // Find the client with matching email
    $client_info = null;
    $client_stats = null;
    foreach ($data['obj'] as $inbound) {
        if (!isset($inbound['settings'])) {
            continue;
        }
        
        $settings = json_decode($inbound['settings'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logger->warning("Error decoding inbound settings", [
                'error' => json_last_error_msg(),
                'inbound_id' => $inbound['id'] ?? 'unknown'
            ]);
            continue;
        }
        
        if (isset($settings['clients'])) {
            foreach ($settings['clients'] as $client) {
                if (isset($client['email']) && $client['email'] === $email) {
                    $client_info = $client;
                    // Get client stats from inbound if available
                    if (isset($inbound['clientStats']) && is_array($inbound['clientStats'])) {
                        foreach ($inbound['clientStats'] as $stat) {
                            if (isset($stat['email']) && $stat['email'] === $email) {
                                $client_stats = $stat;
                                break;
                            }
                        }
                    }
                    break 2;
                }
            }
        }
    }

    if (!$client_info) {
        throw new Exception("Client information not found for email: $email");
    }

    // اضافه کردن لاگ برای بررسی اطلاعات کلاینت
    $logger->info("Client data received", [
        'client_info' => $client_info,
        'client_stats' => $client_stats,
        'email' => $email
    ]);

    // بررسی وجود فیلدهای اصلی در اطلاعات کلاینت
    if (!isset($client_info['totalGB']) || !isset($client_info['expiryTime'])) {
        throw new Exception('Missing required fields in client data');
    }

    // Calculate total and remaining GB
    $total_gb = round($client_info['totalGB'] / (1024 * 1024 * 1024), 2);
    
    // محاسبه حجم مصرف شده
    $used_gb = 0;
    if (isset($client_info['up']) && isset($client_info['down'])) {
        $up = intval($client_info['up']);
        $down = intval($client_info['down']);
        $used_gb = round(($up + $down) / (1024 * 1024 * 1024), 2);
    } 
    else if ($client_stats && isset($client_stats['up']) && isset($client_stats['down'])) {
        $up = intval($client_stats['up']);
        $down = intval($client_stats['down']);
        $used_gb = round(($up + $down) / (1024 * 1024 * 1024), 2);
    }
    
    // محاسبه حجم باقیمانده
    $remaining_gb = max(0, $total_gb - $used_gb);
    
    // محاسبه روزهای باقیمانده
    $expiry_time = $client_info['expiryTime'] / 1000; // تبدیل از میلی‌ثانیه به ثانیه
    $remaining_days = ceil(($expiry_time - time()) / (24 * 60 * 60));
    
    // ساخت متن اطلاعات ترافیک به صورت خلاصه و یک خط
    $traffic_info = "🔄{$used_gb}/{$total_gb}GB | ⌛{$remaining_days}d";
    
    // Generate config links
    $tunnel_config = $config['configtunnel_c']; // کانفیگ تانل شده
    $direct_config = $config['config_c']; // کانفیگ مستقیم
    
    // Generate fixed config with shorter format
    $fixed_config = "vless://uuid@email:443?security=reality&sni=tgju.org&fp=chrome&pbk=tw6uAbjXzRSIKChb6pDbHVNjnU9Don4hbv6dHRkmJx8&sid=e54b5ad736ae4c38&type=tcp&flow=xtls-rprx-vision&encryption=none#" . rawurlencode($traffic_info);
    
    // Add usage information to the config links
    $tunnel_config = str_replace('#usage', "#{$traffic_info}", $tunnel_config);
    $direct_config = str_replace('#usage', "#{$traffic_info}", $direct_config);
    
    // Combine all configs with newline separator
    $combined_configs = $tunnel_config . "\n" . $direct_config . "\n" . $fixed_config;
    
    // Base64 encode the combined configs
    echo base64_encode($combined_configs);

} catch (Exception $e) {
    $logger->error("Error in config_generator.php", [
        'error' => $e->getMessage(),
        'email' => $email,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Create error message and encode it in base64
    echo base64_encode("Error: " . $e->getMessage());
}