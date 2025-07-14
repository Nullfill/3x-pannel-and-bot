<?php
// دریافت تنظیمات دیتابیس از فایل config.php
require_once 'config.php';

// تنظیم توکن برای تلگرام
$token = TOKEN;

// اتصال به دیتابیس
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// بررسی خطای اتصال
if ($conn->connect_error) {
    die("خطا در اتصال به دیتابیس: " . $conn->connect_error);
}

// دریافت لیست کاربران
$users = [];
$result = $conn->query("SELECT * FROM users ORDER BY id DESC");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

/**
 * Send message to user
 */
function sendMessage($chat_id, $text, $keyboard = null) {
    global $token;
    
    error_log("Sending message to user: " . $chat_id . " - Length: " . strlen($text));
    
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];

    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }

    $retryCount = 2;
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
                error_log("CURL Error in sendMessage: " . $curl_error);
                throw new Exception($curl_error);
            }

            if ($http_code !== 200) {
                error_log("HTTP Error in sendMessage: " . $http_code . ", Response: " . $response);
                throw new Exception("HTTP Error: " . $http_code);
            }

            $result = json_decode($response, true);
            if (!isset($result['ok']) || $result['ok'] !== true) {
                error_log("Telegram API Error in sendMessage: " . ($result['description'] ?? 'Unknown error'));
                throw new Exception("Telegram API Error: " . ($result['description'] ?? 'Unknown error'));
            }

            error_log("Message sent to user successfully");
            return true;

        } catch (Exception $e) {
            $retryCount--;
            error_log("Error in sendMessage (attempt " . (2 - $retryCount) . "/2): " . $e->getMessage());
            
            if ($retryCount === 0) {
                error_log("Failed to send message after 2 attempts");
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
    global $token;
    $log_channel_id = "-1001617877546"; // آیدی عددی گروه لاگ
    
    error_log("Sending log to channel - Length: " . strlen($message));
    
    $data = [
        'chat_id' => $log_channel_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];

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
            $curl_errno = curl_errno($ch);
            curl_close($ch);

            if ($response === false) {
                error_log("CURL Error in sendToLogChannel: " . $curl_error . " (Code: " . $curl_errno . ")");
                throw new Exception($curl_error);
            }

            if ($http_code !== 200) {
                error_log("HTTP Error in sendToLogChannel: " . $http_code . ", Response: " . $response);
                error_log("Data sent: " . json_encode($data));
                throw new Exception("HTTP Error: " . $http_code);
            }

            $result = json_decode($response, true);

            if (!isset($result['ok']) || $result['ok'] !== true) {
                error_log("Telegram API Error in sendToLogChannel: " . ($result['description'] ?? 'Unknown error'));
                error_log("Full response: " . json_encode($result));
                throw new Exception("Telegram API Error: " . ($result['description'] ?? 'Unknown error'));
            }

            error_log("Log message sent to channel successfully");
            return true;

        } catch (Exception $e) {
            $retryCount--;
            error_log("Error in sendToLogChannel (attempt " . (3 - $retryCount) . "/3): " . $e->getMessage());
            
            if ($retryCount === 0) {
                error_log("Failed to send log message after 3 attempts");
                return false;
            }
            
            sleep(1); // Wait 1 second before retry
        }
    }
    
    return false;
}

// پردازش تغییر موجودی کیف پول
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_balance'])) {
    $user_id = intval($_POST['user_id']);
    $amount = intval($_POST['amount']);
    $type = $_POST['type']; // 'add' or 'subtract'
    
    try {
        $conn->begin_transaction();
        
        if ($type === 'subtract') {
            // بررسی کافی بودن موجودی
            $stmt = $conn->prepare("SELECT balance FROM users WHERE userid = ?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if (!$user || $user['balance'] < $amount) {
                throw new Exception("موجودی کیف پول کاربر کافی نیست.");
            }
            
            $amount = -$amount; // تبدیل به عدد منفی برای کسر
        }
        
        // به‌روزرسانی موجودی
        $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE userid = ?");
        $stmt->bind_param('ii', $amount, $user_id);
        $stmt->execute();
        
        // دریافت اطلاعات کاربر
        $stmt = $conn->prepare("SELECT name, username, balance FROM users WHERE userid = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_info = $result->fetch_assoc();
        $user_name = $user_info['name'] ?? 'بدون نام';
        $user_username = $user_info['username'] ?? 'بدون نام کاربری';
        $current_balance = $user_info['balance'] ?? 0;
        
        // اصلاح نام کاربری برای مارک‌داون تلگرام
        $escaped_username = str_replace('_', '\_', $user_username);
        
        // ثبت تراکنش
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, ?, ?, NOW())");
        $transaction_type = $type === 'add' ? 'admin_add' : 'admin_subtract';
        $description = $type === 'add' ? 'افزایش موجودی توسط ادمین' : 'کسر موجودی توسط ادمین';
        $stmt->bind_param('iiss', $user_id, $amount, $transaction_type, $description);
        $stmt->execute();
        
        // دریافت اطلاعات ادمین
        $admin_name = $_SESSION['admin_name'] ?? 'ادمین';
        $admin_username = $_SESSION['admin_username'] ?? 'مدیر سیستم';
        
        $conn->commit();
        $message = '<div class="alert success">موجودی کیف پول با موفقیت به‌روزرسانی شد.</div>';
        
        // ارسال پیام به کاربر
        if ($user_id > 0) {
            if ($type === 'add') {
                $user_message = "✅ *افزایش موجودی انجام شد*\n\n" .
                               "💰 مبلغ: " . number_format(abs($amount)) . " تومان به موجودی کیف پول شما افزوده شد.\n" .
                               "⏱ زمان: " . date('Y-m-d H:i:s') . "\n" .
                               "💲 موجودی فعلی: " . number_format($current_balance) . " تومان";
            } else {
                $user_message = "❌ *کسر موجودی انجام شد*\n\n" .
                               "💰 مبلغ: " . number_format(abs($amount)) . " تومان از موجودی کیف پول شما کسر شد.\n" .
                               "⏱ زمان: " . date('Y-m-d H:i:s') . "\n" .
                               "💲 موجودی فعلی: " . number_format($current_balance) . " تومان";
            }
            
            $user_msg_result = sendMessage($user_id, $user_message);
            error_log("User notification sent: " . ($user_msg_result ? 'success' : 'failed'));
        }
        
        // ارسال پیام به کانال لاگ
        if ($type === 'add') {
            $log_message = "✅ *افزایش موجودی دستی*\n\n" .
                         "👤 کاربر: [$user_name](tg://user?id=$user_id)\n" .
                         "🆔 آیدی: `$user_id`\n" .
                         "👤 یوزرنیم: @$escaped_username\n" .
                         "💰 مبلغ: " . number_format(abs($amount)) . " تومان\n" .
                         "⏱ زمان: " . date('Y-m-d H:i:s') . "\n" .
                         "👮‍♂️ ادمین: $admin_name";
        } else {
            $log_message = "❌ *کسر موجودی دستی*\n\n" .
                         "👤 کاربر: [$user_name](tg://user?id=$user_id)\n" .
                         "🆔 آیدی: `$user_id`\n" .
                         "👤 یوزرنیم: @$escaped_username\n" .
                         "💰 مبلغ: " . number_format(abs($amount)) . " تومان\n" .
                         "⏱ زمان: " . date('Y-m-d H:i:s') . "\n" .
                         "👮‍♂️ ادمین: $admin_name";
        }
        
        $log_result = sendToLogChannel($log_message);
        error_log("Log message sent result: " . ($log_result ? 'success' : 'failed'));
        
        if (!$log_result) {
            // در صورت شکست در ارسال به کانال لاگ، یک بار دیگر با تاخیر تلاش کنیم
            sleep(2);
            $log_result = sendToLogChannel($log_message);
            error_log("Second attempt log message sent result: " . ($log_result ? 'success' : 'failed'));
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $message = '<div class="alert error">خطا: ' . $e->getMessage() . '</div>';
    }
}
?>

<div class="content-header">
    <h1>مدیریت کاربران</h1>
</div>

<div class="card">
    <?php echo $message ?? ''; ?>
    
    <div class="users-grid">
        <?php foreach ($users as $user): ?>
            <div class="user-box">
                <div class="user-header">
                    <h3>
                        <?php echo htmlspecialchars($user['name'] ?: 'کاربر بدون نام'); ?>
                        <?php if ($user['username']): ?>
                            <span class="username">@<?php echo htmlspecialchars($user['username']); ?></span>
                        <?php endif; ?>
                    </h3>
                    <div class="user-actions">
                        <button class="edit-balance" onclick="openBalancePopup(<?php echo $user['userid']; ?>, <?php echo $user['balance']; ?>)">
                            <i class="fas fa-wallet"></i>
                            ویرایش موجودی
                        </button>
                        <button class="view-configs" onclick="viewUserConfigs(<?php echo $user['userid']; ?>)">
                            <i class="fas fa-list"></i>
                            کانفیگ‌ها
                        </button>
                        <button class="view-transactions" onclick="viewUserTransactions(<?php echo $user['userid']; ?>)">
                            <i class="fas fa-history"></i>
                            تراکنش‌ها
                        </button>
                    </div>
                </div>
                
                <div class="user-info">
                    <p><i class="fas fa-id-card"></i> شناسه کاربر: <?php echo $user['userid']; ?></p>
                    <p><i class="fas fa-cog"></i> تعداد کانفیگ‌ها: <?php echo $user['configcount']; ?></p>
                    <p><i class="fas fa-wallet"></i> موجودی کیف پول: <?php echo number_format($user['balance']); ?> تومان</p>
                </div>
                
                <div class="user-transactions" id="transactions-<?php echo $user['userid']; ?>" style="display: none;">
                    <h4>تاریخچه تراکنش‌ها</h4>
                    <?php
                    // Get regular transactions
                    $stmt = $conn->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC");
                    $stmt->bind_param('i', $user['userid']);
                    $stmt->execute();
                    $transactions = $stmt->get_result();
                    
                    // Get wallet recharge requests
                    $stmt = $conn->prepare("SELECT * FROM wallet_recharge_requests WHERE user_id = ? ORDER BY created_at DESC");
                    $stmt->bind_param('i', $user['userid']);
                    $stmt->execute();
                    $recharge_requests = $stmt->get_result();
                    
                    if ($transactions->num_rows > 0 || $recharge_requests->num_rows > 0):
                        echo '<ul class="transactions-list">';
                        
                        // Show regular transactions
                        while ($transaction = $transactions->fetch_assoc()):
                            $amount_class = $transaction['amount'] > 0 ? 'positive' : 'negative';
                            $amount_text = $transaction['amount'] > 0 ? '+' : '';
                            $date = new DateTime($transaction['created_at']);
                            $persian_date = new IntlDateFormatter(
                                'fa_IR@calendar=persian',
                                IntlDateFormatter::FULL,
                                IntlDateFormatter::SHORT,
                                'Asia/Tehran',
                                IntlDateFormatter::TRADITIONAL,
                                'yyyy/MM/dd HH:mm'
                            );
                            echo '<li class="transaction-item">';
                            echo '<div class="transaction-info">';
                            echo '<span class="amount ' . $amount_class . '">' . $amount_text . number_format($transaction['amount']) . ' تومان</span>';
                            echo '<span class="date">' . $persian_date->format($date) . '</span>';
                            echo '</div>';
                            echo '<span class="description">' . htmlspecialchars($transaction['description']) . '</span>';
                            echo '</li>';
                        endwhile;
                        
                        // Show wallet recharge requests
                        while ($request = $recharge_requests->fetch_assoc()):
                            $status_class = $request['status'] === 'approved' ? 'positive' : 
                                          ($request['status'] === 'rejected' ? 'negative' : 'pending');
                            $status_text = $request['status'] === 'approved' ? 'تایید شده' : 
                                         ($request['status'] === 'rejected' ? 'رد شده' : 'در انتظار تایید');
                            $date = new DateTime($request['created_at']);
                            $persian_date = new IntlDateFormatter(
                                'fa_IR@calendar=persian',
                                IntlDateFormatter::FULL,
                                IntlDateFormatter::SHORT,
                                'Asia/Tehran',
                                IntlDateFormatter::TRADITIONAL,
                                'yyyy/MM/dd HH:mm'
                            );
                            echo '<li class="transaction-item recharge-request">';
                            echo '<div class="transaction-info">';
                            echo '<span class="amount ' . $status_class . '">' . number_format($request['amount']) . ' تومان</span>';
                            echo '<span class="date">' . $persian_date->format($date) . '</span>';
                            echo '</div>';
                            echo '<span class="description">درخواست افزایش موجودی - ' . $status_text . '</span>';
                            if ($request['receipt_image']) {
                                echo '<a href="' . htmlspecialchars($request['receipt_image']) . '" target="_blank" class="receipt-link">';
                                echo '<i class="fas fa-receipt"></i> مشاهده تصویر رسید';
                                echo '</a>';
                            }
                            echo '</li>';
                        endwhile;
                        
                        echo '</ul>';
                    else:
                        echo '<p class="no-transactions">هیچ تراکنشی یافت نشد.</p>';
                    endif;
                    ?>
                </div>
                
                <div class="user-configs" id="configs-<?php echo $user['userid']; ?>" style="display: none;">
                    <h4>کانفیگ‌های خریداری شده</h4>
                    <?php
                    $stmt = $conn->prepare("SELECT * FROM usersconfig WHERE userid_c = ? ORDER BY created_at DESC");
                    $stmt->bind_param('i', $user['userid']);
                    $stmt->execute();
                    $configs = $stmt->get_result();
                    
                    if ($configs->num_rows > 0):
                        echo '<div class="configs-table-container">';
                        echo '<table class="configs-table">';
                        echo '<thead>';
                        echo '<tr>';
                        echo '<th>نام کانفیگ</th>';
                        echo '<th>ایمیل</th>';
                        echo '<th>تاریخ خرید</th>';
                        echo '<th>عملیات</th>';
                        echo '</tr>';
                        echo '</thead>';
                        echo '<tbody>';
                        while ($config = $configs->fetch_assoc()):
                            $date = new DateTime($config['created_at']);
                            $persian_date = new IntlDateFormatter(
                                'fa_IR@calendar=persian',
                                IntlDateFormatter::FULL,
                                IntlDateFormatter::SHORT,
                                'Asia/Tehran',
                                IntlDateFormatter::TRADITIONAL,
                                'yyyy/MM/dd HH:mm'
                            );
                            echo '<tr>';
                            echo '<td data-label="نام کانفیگ">' . htmlspecialchars($config['name_config']) . '</td>';
                            echo '<td data-label="ایمیل">' . htmlspecialchars($config['email_config']) . '</td>';
                            echo '<td data-label="تاریخ خرید">' . $persian_date->format($date) . '</td>';
                            echo '<td data-label="عملیات">';
                            echo '<button class="view-config-logs" onclick="viewConfigLogs(' . $user['userid'] . ', \'' . htmlspecialchars($config['email_config']) . '\')">';
                            echo '<i class="fas fa-history"></i> مشاهده لاگ';
                            echo '</button>';
                            echo '</td>';
                            echo '</tr>';
                        endwhile;
                        echo '</tbody>';
                        echo '</table>';
                        echo '</div>';
                    else:
                        echo '<p class="no-configs">هیچ کانفیگی یافت نشد.</p>';
                    endif;
                    ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- پاپ‌آپ ویرایش موجودی -->
<div class="overlay" id="balanceOverlay"></div>
<div class="popup" id="balancePopup">
    <h2>ویرایش موجودی کیف پول</h2>
    <form method="POST" action="">
        <input type="hidden" name="user_id" id="balance_user_id">
        <div class="form-group">
            <label>موجودی فعلی:</label>
            <span id="current_balance">0</span> تومان
        </div>
        <div class="form-group">
            <label for="amount">مبلغ:</label>
            <input type="number" id="amount" name="amount" required min="0">
        </div>
        <div class="form-group">
            <label>نوع عملیات:</label>
            <div class="radio-group">
                <label>
                    <input type="radio" name="type" value="add" checked>
                    افزایش موجودی
                </label>
                <label>
                    <input type="radio" name="type" value="subtract">
                    کسر موجودی
                </label>
            </div>
        </div>
        <div class="button-group">
            <button type="submit" name="update_balance" class="submit-btn">
                <i class="fas fa-save"></i>
                ذخیره تغییرات
            </button>
            <button type="button" onclick="closeBalancePopup()" class="cancel-btn">
                <i class="fas fa-times"></i>
                بستن
            </button>
        </div>
    </form>
</div>

<div class="config-logs-popup" id="configLogsPopup" style="display: none;">
    <h3>تاریخچه عملیات کانفیگ</h3>
    <div class="config-logs-content">
        <table class="logs-table">
            <thead>
                <tr>
                    <th>عملیات</th>
                    <th>تاریخ</th>
                    <th>وضعیت</th>
                    <th>جزئیات</th>
                </tr>
            </thead>
            <tbody id="configLogsBody">
            </tbody>
        </table>
    </div>
    <button class="close-popup" onclick="closeConfigLogsPopup()">بستن</button>
</div>

<style>
.users-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 25px;
    margin-top: 30px;
    padding: 20px;
}

.user-box {
    background: white;
    border-radius: 20px;
    padding: 25px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.user-box:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
}

.user-header {
    display: flex;
    flex-direction: column;
    gap: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.user-header h3 {
    margin: 0;
    font-size: 1.2rem;
    color: #2B3674;
    display: flex;
    align-items: center;
    gap: 10px;
}

.username {
    color: #707EAE;
    font-size: 0.9rem;
    font-weight: normal;
}

.user-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
}

.user-actions button {
    flex: 1;
    min-width: 120px;
    padding: 10px 15px;
    border: none;
    border-radius: 10px;
    background: #F8F9FF;
    color: #4318FF;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.user-actions button:hover {
    background: #4318FF;
    color: white;
    transform: translateY(-2px);
}

.user-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    padding: 15px 0;
}

.user-info p {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #707EAE;
    font-size: 0.9rem;
}

.user-info i {
    color: #4318FF;
    font-size: 1.1rem;
}

.user-transactions, .user-configs {
    background: #F8F9FF;
    border-radius: 15px;
    padding: 20px;
    margin-top: 10px;
}

.user-transactions h4, .user-configs h4 {
    margin: 0 0 15px 0;
    color: #2B3674;
    font-size: 1.1rem;
}

.transactions-list, .configs-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.transaction-item {
    background: white;
    padding: 15px;
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: all 0.3s ease;
}

.transaction-item:hover {
    transform: translateX(-5px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.transaction-item.recharge-request {
    border-right: 4px solid #FFB547;
}

.transaction-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.amount {
    font-weight: 600;
    font-size: 1rem;
}

.amount.pending {
    color: #FFB547;
}

.amount.positive {
    color: #047857;
}

.amount.negative {
    color: #991B1B;
}

.date {
    color: #707EAE;
    font-size: 0.85rem;
}

.description {
    color: #707EAE;
    font-size: 0.9rem;
}

.receipt-link {
    color: #4318FF;
    text-decoration: none;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 5px;
}

.receipt-link:hover {
    text-decoration: underline;
}

/* Popup Styles */
.overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(5px);
    z-index: 9999;
}

.popup {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 10000;
    background: white;
    border-radius: 20px;
    padding: 30px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.popup.show {
    display: block;
}

.overlay.show {
    display: block;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #2B3674;
    font-weight: 500;
}

.form-group input[type="number"] {
    width: 100%;
    padding: 12px;
    border: 1px solid #E0E5F2;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s ease;
}

.form-group input[type="number"]:focus {
    border-color: #4318FF;
    outline: none;
    box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.1);
}

.radio-group {
    display: flex;
    gap: 20px;
    margin-top: 10px;
}

.radio-group label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    color: #707EAE;
}

.button-group {
    display: flex;
    gap: 15px;
    margin-top: 25px;
}

.submit-btn, .cancel-btn {
    flex: 1;
    padding: 12px 20px;
    border: none;
    border-radius: 10px;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.submit-btn {
    background: #4318FF;
    color: white;
}

.submit-btn:hover {
    background: #2B1C8F;
    transform: translateY(-2px);
}

.cancel-btn {
    background: #F8F9FF;
    color: #4318FF;
}

.cancel-btn:hover {
    background: #E0E5F2;
    transform: translateY(-2px);
}

/* Responsive Design */
@media (max-width: 768px) {
    .users-grid {
        grid-template-columns: 1fr;
        padding: 10px;
    }

    .user-actions {
        flex-direction: column;
    }

    .user-actions button {
        width: 100%;
    }

    .user-info {
        grid-template-columns: 1fr;
    }

    .popup {
        width: 95%;
        padding: 20px;
    }

    .radio-group {
        flex-direction: column;
        gap: 10px;
    }

    .button-group {
        flex-direction: column;
    }
}

/* Alert Styles */
.alert {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert.success {
    background: #ECFDF3;
    color: #047857;
    border: 1px solid #A6F4C5;
}

.alert.error {
    background: #FEF2F2;
    color: #991B1B;
    border: 1px solid #FECACA;
}

.alert i {
    font-size: 1.2rem;
}

/* Configs Table Styles */
.configs-table-container {
    width: 100%;
    overflow-x: auto;
    margin-top: 15px;
}

.configs-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.configs-table th,
.configs-table td {
    padding: 12px 15px;
    text-align: right;
    border-bottom: 1px solid #E0E5F2;
}

.configs-table th {
    background: #F8F9FF;
    color: #2B3674;
    font-weight: 600;
    font-size: 0.9rem;
}

.configs-table td {
    color: #707EAE;
    font-size: 0.9rem;
}

.configs-table tr:last-child td {
    border-bottom: none;
}

.configs-table tr:hover {
    background: #F8F9FF;
}

/* Responsive Table */
@media (max-width: 768px) {
    .configs-table {
        display: block;
    }

    .configs-table thead {
        display: none;
    }

    .configs-table tbody {
        display: block;
    }

    .configs-table tr {
        display: block;
        margin-bottom: 15px;
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .configs-table td {
        display: block;
        text-align: right;
        padding: 10px 15px;
        border-bottom: 1px solid #E0E5F2;
    }

    .configs-table td:last-child {
        border-bottom: none;
    }

    .configs-table td::before {
        content: attr(data-label);
        float: right;
        font-weight: 600;
        color: #2B3674;
        margin-left: 10px;
    }
}

/* Update existing styles */
.user-configs {
    background: #F8F9FF;
    border-radius: 15px;
    padding: 20px;
    margin-top: 10px;
    overflow: hidden;
}

.user-configs h4 {
    margin: 0 0 15px 0;
    color: #2B3674;
    font-size: 1.1rem;
    padding-bottom: 10px;
    border-bottom: 1px solid #E0E5F2;
}

.no-configs {
    text-align: center;
    color: #707EAE;
    padding: 20px;
    background: white;
    border-radius: 10px;
    margin: 0;
}

.config-logs-popup {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
    max-width: 800px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    z-index: 1000;
}

.config-logs-content {
    margin: 20px 0;
}

.logs-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.logs-table th,
.logs-table td {
    padding: 10px;
    text-align: right;
    border-bottom: 1px solid #eee;
}

.logs-table th {
    background: #f8f9ff;
    font-weight: 600;
}

.view-config-logs {
    background: #4318FF;
    color: white;
    border: none;
    padding: 5px 10px;
    border-radius: 5px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
}

.view-config-logs:hover {
    background: #2B1C8F;
}

.close-popup {
    background: #dc3545;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 5px;
    cursor: pointer;
    margin-top: 20px;
}

.close-popup:hover {
    background: #c82333;
}
</style>

<script>
function openBalancePopup(userId, currentBalance) {
    document.getElementById('balance_user_id').value = userId;
    document.getElementById('current_balance').textContent = currentBalance.toLocaleString();
    document.getElementById('balancePopup').classList.add('show');
    document.getElementById('balanceOverlay').classList.add('show');
}

function closeBalancePopup() {
    document.getElementById('balancePopup').classList.remove('show');
    document.getElementById('balanceOverlay').classList.remove('show');
}

function viewUserTransactions(userId) {
    const transactionsDiv = document.getElementById(`transactions-${userId}`);
    const configsDiv = document.getElementById(`configs-${userId}`);
    
    if (transactionsDiv.style.display === 'none') {
        transactionsDiv.style.display = 'block';
        configsDiv.style.display = 'none';
    } else {
        transactionsDiv.style.display = 'none';
    }
}

function viewUserConfigs(userId) {
    const transactionsDiv = document.getElementById(`transactions-${userId}`);
    const configsDiv = document.getElementById(`configs-${userId}`);
    
    if (configsDiv.style.display === 'none') {
        configsDiv.style.display = 'block';
        transactionsDiv.style.display = 'none';
    } else {
        configsDiv.style.display = 'none';
    }
}

function viewConfigLogs(userId, configEmail) {
    fetch('get_config_logs.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `user_id=${userId}&config_email=${encodeURIComponent(configEmail)}`
    })
    .then(response => response.json())
    .then(data => {
        const logsBody = document.getElementById('configLogsBody');
        logsBody.innerHTML = '';
        
        data.forEach(log => {
            const row = document.createElement('tr');
            
            const actionCell = document.createElement('td');
            actionCell.textContent = getActionText(log.action);
            
            const dateCell = document.createElement('td');
            const date = new Date(log.created_at);
            dateCell.textContent = date.toLocaleString('fa-IR');
            
            const statusCell = document.createElement('td');
            statusCell.textContent = getStatusText(log.status);
            statusCell.className = `status-${log.status}`;
            
            const detailsCell = document.createElement('td');
            detailsCell.textContent = log.error_message || 'موفق';
            
            row.appendChild(actionCell);
            row.appendChild(dateCell);
            row.appendChild(statusCell);
            row.appendChild(detailsCell);
            
            logsBody.appendChild(row);
        });
        
        document.getElementById('configLogsPopup').style.display = 'block';
    })
    .catch(error => {
        console.error('Error:', error);
        alert('خطا در دریافت لاگ‌ها');
    });
}

function closeConfigLogsPopup() {
    document.getElementById('configLogsPopup').style.display = 'none';
}

function getActionText(action) {
    const actions = {
        'create': 'ایجاد',
        'delete': 'حذف',
        'renew': 'تمدید'
    };
    return actions[action] || action;
}

function getStatusText(status) {
    const statuses = {
        'success': 'موفق',
        'failed': 'ناموفق',
        'pending': 'در حال انجام'
    };
    return statuses[status] || status;
}

// Close popup when clicking outside
document.getElementById('balanceOverlay').addEventListener('click', closeBalancePopup);
</script> 