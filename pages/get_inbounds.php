<?php
header('Content-Type: application/json');
require_once __DIR__ . '/tel/utils/Logger.php';

$logger = Logger::getInstance();

// دریافت داده‌های ارسالی از server-login.php
$input = json_decode(file_get_contents('php://input'), true);
$servers = $input['servers'] ?? [];

$logger->info("Received servers data", [
    'server_count' => count($servers)
]);

$response = [
    'success' => false,
    'servers' => []
];

foreach ($servers as $server) {
    $url = rtrim($server['url'], '/') . '/panel/inbound/list';

    $headers = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:134.0) Gecko/20100101 Firefox/134.0",
        "Accept: application/json, text/plain, */*",
        "Accept-Language: en-US,en;q=0.5",
        "Accept-Encoding: gzip, deflate",
        "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
        "X-Requested-With: XMLHttpRequest",
        "Origin: " . $server['url'],
        "Connection: keep-alive",
        "Referer: " . $server['url'] . "/panel/inbounds",
        "Cookie: " . $server['cookies'],
        "Content-Length: 0"
    ];

    $logger->info("Making request to server", [
        'server_name' => $server['name'],
        'url' => $url
    ]);

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_ENCODING, "gzip, deflate"); // Enable gzip compression
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        $logger->error("CURL error for server", [
            'server_name' => $server['name'],
            'error' => $error
        ]);
        
        $response['servers'][] = [
            'name' => $server['name'],
            'url' => $server['url'],
            'error' => 'خطا در ارسال درخواست: ' . $error
        ];
    } else {
        $result = json_decode($result, true);

        if (isset($result['success']) && $result['success'] === true && isset($result['obj'])) {
            $active_inbounds = 0;
            $inactive_inbounds = 0;

            foreach ($result['obj'] as $inbound) {
                if (isset($inbound['enable']) && $inbound['enable'] === true) {
                    $active_inbounds++;
                } else {
                    $inactive_inbounds++;
                }
            }

            $logger->info("Successfully retrieved server data", [
                'server_name' => $server['name'],
                'active_inbounds' => $active_inbounds,
                'inactive_inbounds' => $inactive_inbounds
            ]);

            $response['servers'][] = [
                'name' => $server['name'],
                'url' => $server['url'],
                'active_inbounds' => $active_inbounds,
                'inactive_inbounds' => $inactive_inbounds
            ];
            $response['success'] = true;
        } else {
            $logger->error("Invalid response from server", [
                'server_name' => $server['name'],
                'response' => $result
            ]);
            
            $response['servers'][] = [
                'name' => $server['name'],
                'url' => $server['url'],
                'error' => 'خطا در دریافت اطلاعات اینباندها'
            ];
        }
    }

    curl_close($ch);
}

$logger->info("Sending response", [
    'success' => $response['success'],
    'server_count' => count($response['servers'])
]);

echo json_encode($response);
?>