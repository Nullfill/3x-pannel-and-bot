<?php
require_once __DIR__ . '/../utils/Logger.php';

$logger = Logger::getInstance();

// تابع برای تشخیص پروتکل و تولید لینک
function generateLink($data, $serverAddress) {
    global $logger;
    $data = json_decode($data, true);
    $obj = $data['obj'];

    $protocol = $obj['protocol'];
    $settings = json_decode($obj['settings'], true);
    $streamSettings = json_decode($obj['streamSettings'], true);
    $remark = $obj['remark'];
    $port = $obj['port'];

    $logger->info("Generating link", [
        'protocol' => $protocol,
        'port' => $port,
        'remark' => $remark
    ]);

    switch ($protocol) {
        case 'vmess':
            return generateVMessLink($settings, $streamSettings, $remark, $port, $serverAddress);
        case 'vless':
            return generateVLESSLink($settings, $streamSettings, $remark, $port, $serverAddress);
        case 'shadowsocks':
            return generateSSLink($settings, $streamSettings, $remark, $port, $serverAddress);
        case 'trojan':
            return generateTrojanLink($settings, $streamSettings, $remark, $port, $serverAddress);
        default:
            $logger->error("Unsupported protocol", ['protocol' => $protocol]);
            return "Unsupported protocol: $protocol";
    }
}

// تابع برای تولید لینک VMess
function generateVMessLink($settings, $streamSettings, $remark, $port, $serverAddress) {
    global $logger;
    $client = $settings['clients'][0];

    $uuid = $client['id'];
    $security = $streamSettings['security'];
    $network = $streamSettings['network'];

    $params = [
        'v' => '2',
        'ps' => $remark,
        'add' => $serverAddress,
        'port' => $port,
        'id' => $uuid,
        'scy' => $client['security'] ?? 'auto',
        'net' => $network,
        'type' => 'none',
        'tls' => $security,
    ];

    // تنظیمات شبکه
    if ($network === 'tcp') {
        $tcpSettings = $streamSettings['tcpSettings'];
        $params['type'] = $tcpSettings['header']['type'];
        if ($params['type'] === 'http') {
            $request = $tcpSettings['header']['request'];
            $params['path'] = implode(',', $request['path']);
            if (isset($request['headers']['Host'])) {
                $params['host'] = $request['headers']['Host'][0];
            }
        }
    } elseif ($network === 'ws') {
        $wsSettings = $streamSettings['wsSettings'];
        $params['path'] = $wsSettings['path'];
        if (isset($wsSettings['headers']['Host'])) {
            $params['host'] = $wsSettings['headers']['Host'][0];
        }
    }

    // تنظیمات TLS
    if ($security === 'tls') {
        $tlsSettings = $streamSettings['tlsSettings'];
        if (isset($tlsSettings['serverName'])) {
            $params['sni'] = $tlsSettings['serverName'];
        }
        if (isset($tlsSettings['settings']['fingerprint'])) {
            $params['fp'] = $tlsSettings['settings']['fingerprint'];
        }
        if (isset($tlsSettings['alpn'])) {
            $params['alpn'] = implode(',', $tlsSettings['alpn']);
        }
        if (isset($tlsSettings['settings']['allowInsecure'])) {
            $params['allowInsecure'] = $tlsSettings['settings']['allowInsecure'] ? '1' : '0';
        }
    }

    $link = "vmess://" . base64_encode(json_encode($params));
    $logger->info("Generated VMess link", ['remark' => $remark]);
    return $link;
}

// تابع برای تولید لینک VLESS
function generateVLESSLink($settings, $streamSettings, $remark, $port, $serverAddress) {
    global $logger;
    $client = $settings['clients'][0];

    $uuid = $client['id'];
    $security = $streamSettings['security'];
    $network = $streamSettings['network'];

    $params = [
        'type' => $network,
        'security' => $security,
    ];

    // تنظیمات شبکه
    if ($network === 'tcp') {
        $tcpSettings = $streamSettings['tcpSettings'];
        if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] === 'http') {
            $request = $tcpSettings['header']['request'];
            $params['path'] = implode(',', $request['path']);
            if (isset($request['headers']['Host'])) {
                $params['host'] = $request['headers']['Host'][0];
            }
            $params['headerType'] = 'http';
        }
    } elseif ($network === 'ws') {
        $wsSettings = $streamSettings['wsSettings'];
        $params['path'] = $wsSettings['path'];
        if (isset($wsSettings['headers']['Host'])) {
            $params['host'] = $wsSettings['headers']['Host'][0];
        }
    }

    // تنظیمات TLS
    if ($security === 'tls') {
        $tlsSettings = $streamSettings['tlsSettings'];
        if (isset($tlsSettings['serverName'])) {
            $params['sni'] = $tlsSettings['serverName'];
        }
        if (isset($tlsSettings['settings']['fingerprint'])) {
            $params['fp'] = $tlsSettings['settings']['fingerprint'];
        }
        if (isset($tlsSettings['alpn'])) {
            $params['alpn'] = implode(',', $tlsSettings['alpn']);
        }
        if (isset($tlsSettings['settings']['allowInsecure'])) {
            $params['allowInsecure'] = $tlsSettings['settings']['allowInsecure'] ? '1' : '0';
        }
    }

    // تنظیمات Reality
    if ($security === 'reality') {
        $realitySettings = $streamSettings['realitySettings'];
        $params['pbk'] = $realitySettings['settings']['publicKey'];
        $params['fp'] = $realitySettings['settings']['fingerprint'];
        $params['sni'] = $realitySettings['serverNames'][0];
        $params['sid'] = $realitySettings['shortIds'][0];
        $params['spx'] = $realitySettings['settings']['spiderX'];
    }

    // حذف پارامترهای خالی
    $params = array_filter($params, function($value) {
        return $value !== null && $value !== '';
    });

    // ساخت لینک VLESS
    $link = "vless://$uuid@$serverAddress:$port?" . http_build_query($params);
    $link .= "#" . urlencode($remark);
    $file = 'file.txt';

    // ذخیره لینک در فایل
    file_put_contents($file, $link . PHP_EOL, FILE_APPEND);
    $logger->info("Generated and saved VLESS link", ['remark' => $remark]);
    return $link;
}

// تابع برای تولید لینک Shadowsocks
function generateSSLink($settings, $streamSettings, $remark, $port, $serverAddress) {
    global $logger;
    $client = $settings['clients'][0];

    $method = $settings['method'];
    $password = $client['password'];
    $security = $streamSettings['security'];
    $network = $streamSettings['network'];

    $params = [
        'type' => $network,
        'security' => $security,
    ];

    // تنظیمات شبکه
    if ($network === 'tcp') {
        $tcpSettings = $streamSettings['tcpSettings'];
        if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] === 'http') {
            $request = $tcpSettings['header']['request'];
            $params['path'] = implode(',', $request['path']);
            if (isset($request['headers']['Host'])) {
                $params['host'] = $request['headers']['Host'][0];
            }
            $params['headerType'] = 'http';
        }
    } elseif ($network === 'ws') {
        $wsSettings = $streamSettings['wsSettings'];
        $params['path'] = $wsSettings['path'];
        if (isset($wsSettings['headers']['Host'])) {
            $params['host'] = $wsSettings['headers']['Host'][0];
        }
    }

    // حذف پارامترهای خالی
    $params = array_filter($params, function($value) {
        return $value !== null && $value !== '';
    });

    // ساخت لینک Shadowsocks
    $link = "ss://" . base64_encode("$method:$password") . "@$serverAddress:$port?" . http_build_query($params);
    $link .= "#" . urlencode($remark);
    $logger->info("Generated SS link", ['remark' => $remark]);
    return $link;
}

// تابع برای تولید لینک Trojan
function generateTrojanLink($settings, $streamSettings, $remark, $port, $serverAddress) {
    global $logger;
    $client = $settings['clients'][0];

    $password = $client['password'];
    $security = $streamSettings['security'];
    $network = $streamSettings['network'];

    $params = [
        'type' => $network,
        'security' => $security,
    ];

    // تنظیمات شبکه
    if ($network === 'tcp') {
        $tcpSettings = $streamSettings['tcpSettings'];
        if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] === 'http') {
            $request = $tcpSettings['header']['request'];
            $params['path'] = implode(',', $request['path']);
            if (isset($request['headers']['Host'])) {
                $params['host'] = $request['headers']['Host'][0];
            }
            $params['headerType'] = 'http';
        }
    } elseif ($network === 'ws') {
        $wsSettings = $streamSettings['wsSettings'];
        $params['path'] = $wsSettings['path'];
        if (isset($wsSettings['headers']['Host'])) {
            $params['host'] = $wsSettings['headers']['Host'][0];
        }
    }

    // تنظیمات TLS
    if ($security === 'tls') {
        $tlsSettings = $streamSettings['tlsSettings'];
        if (isset($tlsSettings['serverName'])) {
            $params['sni'] = $tlsSettings['serverName'];
        }
        if (isset($tlsSettings['settings']['fingerprint'])) {
            $params['fp'] = $tlsSettings['settings']['fingerprint'];
        }
        if (isset($tlsSettings['alpn'])) {
            $params['alpn'] = implode(',', $tlsSettings['alpn']);
        }
        if (isset($tlsSettings['settings']['allowInsecure'])) {
            $params['allowInsecure'] = $tlsSettings['settings']['allowInsecure'] ? '1' : '0';
        }
    }

    // حذف پارامترهای خالی
    $params = array_filter($params, function($value) {
        return $value !== null && $value !== '';
    });

    // ساخت لینک Trojan
    $link = "trojan://$password@$serverAddress:$port?" . http_build_query($params);
    $link .= "#" . urlencode($remark);
    $logger->info("Generated Trojan link", ['remark' => $remark]);
    return $link;
}

// دریافت داده‌ها از POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverAddress = $_POST['serverAddress'];
    $jsonData = $_POST['jsonData'];

    $logger->info("Received POST request", [
        'server_address' => $serverAddress
    ]);

    // تولید لینک
    $link = generateLink($jsonData, $serverAddress);
    echo "Generated Link: $link\n";
} else {
    $logger->warning("Invalid request method", [
        'method' => $_SERVER['REQUEST_METHOD']
    ]);
    echo "Please send a POST request with 'serverAddress' and 'jsonData'.";
}