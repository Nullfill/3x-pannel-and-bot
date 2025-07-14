<?php
ob_start();
$message = '';
$cookies_info = '';
$inbound_info = '';

// دریافت تنظیمات دیتابیس از فایل config.php
require_once 'config.php';

// اتصال اولیه به MySQL بدون انتخاب پایگاه داده
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

// بررسی خطای اتصال
if ($conn->connect_error) {
    die("خطا در اتصال به MySQL: " . $conn->connect_error);
}

// ایجاد دیتابیس اگر وجود نداشته باشد
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
if ($conn->query($sql) === TRUE) {
    // انتخاب پایگاه داده
    $conn->select_db(DB_NAME);

    // ایجاد جدول اگر وجود نداشته باشد
    $sql = "CREATE TABLE IF NOT EXISTS servers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        url VARCHAR(255) NOT NULL,
        cookies TEXT NOT NULL,
        tunnel_ip VARCHAR(255),
        capacity VARCHAR(255), -- اضافه کردن فیلد جدید
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    if ($conn->query($sql) !== TRUE) {
        die("خطا در ایجاد جدول: " . $conn->error);
    }

    // بررسی وجود فیلد capacity و اضافه کردن آن اگر وجود نداشته باشد
    $check_column = $conn->query("SHOW COLUMNS FROM servers LIKE 'capacity'");
    if ($check_column->num_rows == 0) {
        $sql = "ALTER TABLE servers ADD COLUMN capacity VARCHAR(255) AFTER tunnel_ip";
        if ($conn->query($sql) !== TRUE) {
            die("خطا در اضافه کردن فیلد capacity: " . $conn->error);
        }
    }
} else {
    die("خطا در ایجاد دیتابیس: " . $conn->error);
}

// خواندن سرورهای ذخیره شده از دیتابیس
$servers = [];
$result = $conn->query("SELECT * FROM servers");
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $servers[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['edit_server'])) {
        // ویرایش سرور
        $id = intval($_POST['id']);
        $server_name = $_POST['server_name'] ?? '';
        $server_url = $_POST['server_url'] ?? '';
        $tunnel_ip = $_POST['tunnel_ip'] ?? '';
        $capacity = $_POST['capacity'] ?? ''; // دریافت ظرفیت سرور از فرم

        if (!empty($server_name) && !empty($server_url)) {
            $stmt = $conn->prepare("UPDATE servers SET name = ?, url = ?, tunnel_ip = ?, capacity = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $server_name, $server_url, $tunnel_ip, $capacity, $id);
            if ($stmt->execute()) {
                $message = '<div class="alert success">اطلاعات سرور با موفقیت ویرایش شد.</div>';
            } else {
                $message = '<div class="alert error">خطا در ویرایش اطلاعات سرور.</div>';
            }
            $stmt->close();
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
            exit();
        } else {
            $message = '<div class="alert error">لطفا تمام فیلدها را پر کنید</div>';
        }
    } else {
        // افزودن سرور جدید
        $server_name = $_POST['server_name'] ?? '';
        $server_url = $_POST['server_url'] ?? '';
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $tunnel_ip = $_POST['tunnel_ip'] ?? '';
        $capacity = $_POST['capacity'] ?? ''; // دریافت ظرفیت سرور از فرم

        if (!empty($server_name) && !empty($server_url) && !empty($username) && !empty($password)) {
            // اضافه کردن /login به آدرس سرور
            $login_url = rtrim($server_url, '/') . '/login';

            $ch = curl_init($login_url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'username' => $username,
                    'password' => $password
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json'
                ]
            ]);

            $response = curl_exec($ch);
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($response, 0, $header_size);
            $body = substr($response, $header_size);
            $result = json_decode($body, true);

            preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headers, $matches);

            if (isset($result['success']) && $result['success'] === true) {
                $message = '<div class="alert success">ورود موفقیت آمیز</div>';
                if (!empty($matches[1])) {
                    $cookies = implode('; ', $matches[1]);

                    // ذخیره اطلاعات سرور در دیتابیس
                    $stmt = $conn->prepare("INSERT INTO servers (name, url, cookies, tunnel_ip, capacity) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssss", $server_name, $server_url, $cookies, $tunnel_ip, $capacity); // اضافه کردن capacity
                    if ($stmt->execute()) {
                        $message .= '<div class="alert success">اطلاعات سرور با موفقیت ذخیره شد.</div>';
                    } else {
                        $message .= '<div class="alert error">خطا در ذخیره اطلاعات سرور.</div>';
                    }
                    $stmt->close();

                    $cookies_info = '<div class="cookies-container">';
                    $cookies_info .= '<h2>کوکی‌های دریافت شده:</h2>';
                    $cookies_info .= '<div class="cookies-list">';
                    foreach ($matches[1] as $cookie) {
                        $cookies_info .= '<div class="cookie-item">' . htmlspecialchars($cookie) . '</div>';
                    }
                    $cookies_info .= '</div></div>';
                }
            } else {
                $message = '<div class="alert error">خطا در ورود</div>';
            }
            curl_close($ch);
        } else {
            $message = '<div class="alert error">لطفا تمام فیلدها را پر کنید</div>';
        }
    }
}

// حذف سرور
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM servers WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = '<div class="alert success">سرور با موفقیت حذف شد.</div>';
    } else {
        $message = '<div class="alert error">خطا در حذف سرور.</div>';
    }
    $stmt->close();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}

// ویرایش سرور
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM servers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $server_to_edit = $result->fetch_assoc();
    $stmt->close();
}
ob_end_flush();
?>

<div class="content-header">
    <h1>مدیریت سرورها</h1>
</div>

<?php echo $message; ?>

<div class="card">
    <h2><?php echo isset($server_to_edit) ? 'ویرایش سرور' : 'افزودن سرور جدید'; ?></h2>
    <form method="POST" action="">
        <?php if (isset($server_to_edit)): ?>
            <input type="hidden" name="id" value="<?php echo $server_to_edit['id']; ?>">
            <input type="hidden" name="edit_server" value="1">
        <?php endif; ?>
        
        <div class="form-group">
            <label for="server_name">نام سرور:</label>
            <input type="text" id="server_name" name="server_name" value="<?php echo isset($server_to_edit) ? $server_to_edit['name'] : ''; ?>" required>
        </div>

        <div class="form-group">
            <label for="server_url">آدرس سرور:</label>
            <input type="url" id="server_url" name="server_url" value="<?php echo isset($server_to_edit) ? $server_to_edit['url'] : ''; ?>" required>
        </div>

        <?php if (!isset($server_to_edit)): ?>
            <div class="form-group">
                <label for="username">نام کاربری:</label>
                <input type="text" id="username" name="username" required>
            </div>

            <div class="form-group">
                <label for="password">رمز عبور:</label>
                <input type="password" id="password" name="password" required>
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="tunnel_ip">آدرس تانل:</label>
            <input type="text" id="tunnel_ip" name="tunnel_ip" value="<?php echo isset($server_to_edit) ? $server_to_edit['tunnel_ip'] : ''; ?>">
        </div>

        <div class="form-group">
            <label for="capacity">ظرفیت سرور:</label>
            <input type="text" id="capacity" name="capacity" value="<?php echo isset($server_to_edit) ? $server_to_edit['capacity'] : ''; ?>">
        </div>

        <button type="submit"><?php echo isset($server_to_edit) ? 'ویرایش سرور' : 'افزودن سرور'; ?></button>
    </form>
</div>

<?php if (!empty($cookies_info)): ?>
<div class="card">
    <?php echo $cookies_info; ?>
</div>
<?php endif; ?>

<?php if (!empty($servers)): ?>
<div class="card">
    <h2>لیست سرورها</h2>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>نام سرور</th>
                    <th>آدرس سرور</th>
                    <th>آدرس تانل</th>
                    <th>ظرفیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($servers as $server): ?>
                <tr>
                    <td><?php echo htmlspecialchars($server['name']); ?></td>
                    <td><?php echo htmlspecialchars($server['url']); ?></td>
                    <td><?php echo htmlspecialchars($server['tunnel_ip']); ?></td>
                    <td><?php echo htmlspecialchars($server['capacity']); ?></td>
                    <td>
                        <a href="?page=server-login&edit=<?php echo $server['id']; ?>" class="btn-edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="?page=server-login&delete=<?php echo $server['id']; ?>" class="btn-delete" onclick="return confirm('آیا از حذف این سرور اطمینان دارید؟')">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>