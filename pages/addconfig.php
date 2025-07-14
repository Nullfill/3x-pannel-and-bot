<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// دریافت تنظیمات دیتابیس از فایل config.php
require_once 'config.php';

// اتصال به دیتابیس
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// بررسی خطای اتصال
if ($conn->connect_error) {
    die("خطا در اتصال به دیتابیس: " . $conn->connect_error);
}

// بررسی وجود جدول configs و ایجاد آن در صورت عدم وجود
$table_check_query = "SHOW TABLES LIKE 'configs'";
$result = $conn->query($table_check_query);

if ($result === FALSE) {
    die("خطا در بررسی وجود جدول configs: " . $conn->error);
}

if ($result->num_rows == 0) {
    // اگر جدول configs وجود نداشت، آن را ایجاد کنید
    $create_table_query = "CREATE TABLE configs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        config_name VARCHAR(255) NOT NULL,
        config_settings TEXT NOT NULL,
        port_type ENUM('single_port', 'multi_port') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    if ($conn->query($create_table_query) === TRUE) {
        echo '<div class="alert success">جدول configs با موفقیت ایجاد شد.</div>';
    } else {
        die("خطا در ایجاد جدول configs: " . $conn->error);
    }
}

// بررسی وجود جدول config_servers و ایجاد آن در صورت عدم وجود
$table_check_query = "SHOW TABLES LIKE 'config_servers'";
$result = $conn->query($table_check_query);

if ($result === FALSE) {
    die("خطا در بررسی وجود جدول config_servers: " . $conn->error);
}

if ($result->num_rows == 0) {
    // اگر جدول config_servers وجود نداشت، آن را ایجاد کنید
    $create_table_query = "CREATE TABLE config_servers (
        config_id INT NOT NULL,
        server_id INT NOT NULL,
        PRIMARY KEY (config_id, server_id),
        FOREIGN KEY (config_id) REFERENCES configs(id) ON DELETE CASCADE,
        FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
    )";
    
    if ($conn->query($create_table_query) === TRUE) {
        echo '<div class="alert success">جدول config_servers با موفقیت ایجاد شد.</div>';
    } else {
        die("خطا در ایجاد جدول config_servers: " . $conn->error);
    }
}

// خواندن سرورهای ذخیره شده از دیتابیس
$servers = [];
$result = $conn->query("SELECT * FROM servers");

if ($result === FALSE) {
    die("خطا در خواندن سرورها از دیتابیس: " . $conn->error);
}

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $servers[] = $row;
    }
} else {
    echo '<div class="alert info">هیچ سروری در دیتابیس وجود ندارد.</div>';
}

// خواندن کانفیگ‌های ذخیره شده از دیتابیس
$configs = [];
$result = $conn->query("
    SELECT c.id, c.config_name, c.config_settings, c.port_type, GROUP_CONCAT(cs.server_id) AS server_ids
    FROM configs c
    LEFT JOIN config_servers cs ON c.id = cs.config_id
    GROUP BY c.id
");

if ($result === FALSE) {
    die("خطا در خواندن کانفیگ‌ها از دیتابیس: " . $conn->error);
}

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $row['server_ids'] = explode(',', $row['server_ids']);
        $configs[] = $row;
    }
} else {
    echo '<div class="alert info">هیچ کانفیگی در دیتابیس وجود ندارد.</div>';
}

// ذخیره کانفیگ جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_config'])) {
    $config_name = $_POST['config_name'] ?? '';
    $config_settings = $_POST['config_settings'] ?? '';
    $port_type = $_POST['port_type'] ?? 'single_port';
    $server_ids = $_POST['server_ids'] ?? [];

    if (!empty($config_name) && !empty($config_settings) && !empty($server_ids)) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO configs (config_name, config_settings, port_type) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $config_name, $config_settings, $port_type);
            if ($stmt->execute()) {
                $config_id = $stmt->insert_id;
                foreach ($server_ids as $server_id) {
                    $stmt2 = $conn->prepare("INSERT INTO config_servers (config_id, server_id) VALUES (?, ?)");
                    $stmt2->bind_param("ii", $config_id, $server_id);
                    $stmt2->execute();
                }
                $conn->commit();
                echo '<div class="alert success">کانفیگ با موفقیت ذخیره شد.</div>';
            } else {
                $conn->rollback();
                echo '<div class="alert error">خطا در ذخیره کانفیگ: ' . $stmt->error . '</div>';
            }
        } catch (Exception $e) {
            $conn->rollback();
            echo '<div class="alert error">خطا در ذخیره کانفیگ: ' . $e->getMessage() . '</div>';
        }
    } else {
        echo '<div class="alert error">لطفا تمام فیلدها را پر کنید و حداقل یک سرور انتخاب کنید.</div>';
    }
}

// ویرایش کانفیگ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_config'])) {
    $config_id = intval($_POST['config_id']);
    $config_name = $_POST['config_name'] ?? '';
    $config_settings = $_POST['config_settings'] ?? '';
    $port_type = $_POST['port_type'] ?? 'single_port';
    $server_ids = $_POST['server_ids'] ?? [];

    if (!empty($config_name) && !empty($config_settings) && !empty($server_ids)) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE configs SET config_name = ?, config_settings = ?, port_type = ? WHERE id = ?");
            $stmt->bind_param("sssi", $config_name, $config_settings, $port_type, $config_id);
            if ($stmt->execute()) {
                $stmt2 = $conn->prepare("DELETE FROM config_servers WHERE config_id = ?");
                $stmt2->bind_param("i", $config_id);
                $stmt2->execute();
                foreach ($server_ids as $server_id) {
                    $stmt3 = $conn->prepare("INSERT INTO config_servers (config_id, server_id) VALUES (?, ?)");
                    $stmt3->bind_param("ii", $config_id, $server_id);
                    $stmt3->execute();
                }
                $conn->commit();
                echo '<div class="alert success">کانفیگ با موفقیت ویرایش شد.</div>';
            } else {
                $conn->rollback();
                echo '<div class="alert error">خطا در ویرایش کانفیگ: ' . $stmt->error . '</div>';
            }
        } catch (Exception $e) {
            $conn->rollback();
            echo '<div class="alert error">خطا در ویرایش کانفیگ: ' . $e->getMessage() . '</div>';
        }
    } else {
        echo '<div class="alert error">لطفا تمام فیلدها را پر کنید و حداقل یک سرور انتخاب کنید.</div>';
    }
}

// حذف کانفیگ
if (isset($_GET['delete_config'])) {
    $config_id = intval($_GET['delete_config']);

    // شروع تراکنش
    $conn->begin_transaction();

    try {
        // حذف رکوردهای مرتبط در جدول config_servers
        $stmt1 = $conn->prepare("DELETE FROM config_servers WHERE config_id = ?");
        $stmt1->bind_param("i", $config_id);
        $stmt1->execute();
        $stmt1->close();

        // حذف رکورد اصلی از جدول configs
        $stmt2 = $conn->prepare("DELETE FROM configs WHERE id = ?");
        $stmt2->bind_param("i", $config_id);
        $stmt2->execute();
        $stmt2->close();

        // کامیت تراکنش
        $conn->commit();
        echo '<div class="alert success">کانفیگ با موفقیت حذف شد.</div>';
    } catch (Exception $e) {
        // در صورت خطا، تراکنش را بازگردانید
        $conn->rollback();
        echo '<div class="alert error">خطا در حذف کانفیگ: ' . $e->getMessage() . '</div>';
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>افزودن کانفیگ</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            direction: rtl;
        }

        .content-header {
           
            color: #fff;
            padding: 20px;
            text-align: right;
        }

        .card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin: 20px;
            padding: 20px;
        }

        .server-list {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

	.server-box {
		border: 1px solid #ccc;
		border-radius: 8px;
		padding: 16px;
		margin-bottom: 16px;
		background-color: #f9f9f9;
		box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
		max-width: 100%; /* اضافه شد */
		overflow-wrap: break-word; /* اضافه شد */
		word-wrap: break-word; /* اضافه شد */
	}
	.server-box h2 {
		margin-top: 0;
		color: #333;
		font-size: 1.2em; /* اضافه شد */
		word-break: break-word; /* اضافه شد */
	}

	.server-box p {
		margin: 8px 0;
		color: #555;
		word-break: break-word; /* اضافه شد */
		overflow-wrap: break-word; /* اضافه شد */
	}
@media (max-width: 768px) {
    .server-box {
        flex: 1 1 calc(50% - 20px);
        min-width: 200px; /* کاهش حداقل عرض در نمایشگرهای کوچکتر */
    }
}

@media (max-width: 480px) {
    .server-box {
        flex: 1 1 100%;
        min-width: auto; /* حذف حداقل عرض در موبایل */
        margin: 10px 0;
    }
}

        .add-config-btn {
            margin-top: 10px;
            padding: 10px 20px;
            background-color: #28a745;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            text-align: center;
        }

        .add-config-btn:hover {
            background-color: #218838;
        }

        .config-list {
            margin-top: 20px;
        }

        .config-item {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            position: relative;
        }

        .config-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .config-item h3 {
            color: #2B3674;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 15px;
            padding-left: 60px;
        }

        .config-item p {
            color: #707EAE;
            margin: 8px 0;
            font-size: 14px;
            line-height: 1.6;
        }

        .config-item .settings-text {
            background: #F8F9FF;
            padding: 10px;
            border-radius: 10px;
            margin: 10px 0;
            font-size: 13px;
            color: #707EAE;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }

        .config-actions {
            position: absolute;
            top: 20px;
            left: 20px;
            display: flex;
            gap: 8px;
        }

        .config-actions button {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 0;
        }

        .config-actions button i {
            font-size: 14px;
        }

        .config-actions button.edit {
            background: linear-gradient(90deg, #FFB547 0%, #FFD574 100%);
            color: white;
        }

        .config-actions button.delete {
            background: linear-gradient(90deg, #FF5B5B 0%, #FF8080 100%);
            color: white;
        }

        .config-actions button:hover {
            transform: scale(1.1);
        }

        .popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            background: white;
            border-radius: 15px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .popup h2 {
            color: #2B3674;
            margin-bottom: 20px;
            font-size: 24px;
        }

        .popup input,
        .popup textarea,
        .popup select {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 2px solid #E0E5F2;
            border-radius: 10px;
            font-size: 14px;
            color: #2B3674;
            transition: all 0.3s ease;
        }

        .popup textarea {
            min-height: 100px;
            resize: vertical;
        }

        .popup select[multiple] {
            height: 120px;
        }

        .popup input:focus,
        .popup textarea:focus,
        .popup select:focus {
            border-color: #4318FF;
            box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.1);
            outline: none;
        }

        .popup .form-group {
            margin-bottom: 20px;
        }

        .popup .form-group label {
            display: block;
            color: #2B3674;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .popup button {
            padding: 12px 25px;
            border-radius: 10px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 10px;
        }

        .popup button[type="submit"] {
            background: linear-gradient(90deg, #4318FF 0%, #9f87ff 100%);
            color: white;
            border: none;
        }

        .popup button[type="button"] {
            background: #E0E5F2;
            color: #2B3674;
            border: none;
        }

        .popup button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 999;
        }

        .alert {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 10px;
        }

        .alert.success {
            background-color: #d4edda;
            color: #155724;
        }

        .alert.error {
            background-color: #f8d7da;
            color: #721c24;
        }

        .alert.info {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        @media (max-width: 768px) {
            .server-box {
                flex: 1 1 calc(50% - 20px);
            }
        }

        @media (max-width: 480px) {
            .server-box {
                flex: 1 1 100%;
                margin: 10px 0;
            }

            .card {
                margin: 10px;
                padding: 10px;
            }
        }

        .config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .add-config-btn {
            background: linear-gradient(90deg, #4318FF 0%, #9f87ff 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 15px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 30px;
        }

        .add-config-btn i {
            font-size: 18px;
        }

        .add-config-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 24, 255, 0.2);
        }

        .popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            background: white;
            border-radius: 15px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 999;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: #2B3674;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #E0E5F2;
            border-radius: 15px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus, .form-group select:focus {
            border-color: #4318FF;
            box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.1);
            outline: none;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .button-group button {
            flex: 1;
            padding: 12px;
            border-radius: 15px;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .submit-btn {
            background: linear-gradient(90deg, #4318FF 0%, #9f87ff 100%);
            color: white;
            border: none;
        }

        .cancel-btn {
            background: #E0E5F2 !important;
            color: #2B3674 !important;
            border: none;
        }

        @media (max-width: 768px) {
            .config-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .config-item {
                padding: 20px;
            }

            .add-config-btn {
                width: 100%;
                justify-content: center;
            }
        }

        .config-item pre {
            background: #F8F9FF;
            padding: 10px;
            border-radius: 10px;
            margin: 10px 0;
            font-size: 12px;
            color: #707EAE;
            max-height: 100px;
            overflow: hidden;
            position: relative;
            transition: max-height 0.3s ease;
        }

        .config-item pre.expanded {
            max-height: none;
        }

        .config-item .show-more {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, #F8F9FF);
            padding: 5px;
            text-align: center;
            cursor: pointer;
            color: #4318FF;
            font-size: 12px;
            display: none;
        }

        .config-item pre:not(.expanded) + .show-more {
            display: block;
        }
    </style>
</head>
<body>
    <div class="content-header">
        <h1>افزودن کانفیگ</h1>
    </div>
    <div class="card">
        <h2>سرورهای اضافه شده</h2>
        <div class="server-grid">
            <?php if (!empty($servers)): ?>
                <?php foreach ($servers as $server): ?>
                    <div class="server-box">
                        <h2><?= htmlspecialchars($server['name']) ?></h2>
                        <p><i class="fas fa-link"></i> آدرس سرور: <?= htmlspecialchars($server['url']) ?></p>
                        <p><i class="fas fa-network-wired"></i> آدرس IP تانل: <?= empty($server['tunnel_ip']) ? 'ندارد' : htmlspecialchars($server['tunnel_ip']) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert info">هیچ سروری در دیتابیس وجود ندارد.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>کانفیگ‌ها</h2>
        <button class="add-config-btn" onclick="openPopup()">
            <i class="fas fa-plus"></i>
            افزودن کانفیگ سراسری
        </button>
        
        <div class="config-grid">
            <?php if (!empty($configs)): ?>
                <?php foreach ($configs as $config): ?>
                    <div class="config-item">
                        <div class="config-actions">
                            <button class="edit" onclick="openEditPopup(<?= $config['id'] ?>, '<?= htmlspecialchars($config['config_name']) ?>', '<?= htmlspecialchars($config['config_settings']) ?>', '<?= $config['port_type'] ?>', [<?= implode(',', $config['server_ids']) ?>])" title="ویرایش">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="delete" onclick="deleteConfig(<?= $config['id'] ?>)" title="حذف">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                        <h3><?= htmlspecialchars($config['config_name']) ?></h3>
                        <p><i class="fas fa-plug"></i> نوع پورت: <?= $config['port_type'] === 'single_port' ? 'تک پورت' : 'مولتی پورت' ?></p>
                        <p><i class="fas fa-code"></i> تنظیمات:</p>
                        <div class="settings-text" title="<?= htmlspecialchars($config['config_settings']) ?>">
                            <?= mb_substr(htmlspecialchars($config['config_settings']), 0, 5) . '...' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert info">هیچ کانفیگی در دیتابیس وجود ندارد.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- پاپ‌آپ برای افزودن کانفیگ -->
    <div class="overlay" id="overlay"></div>
    <div class="popup" id="popup">
        <h2>افزودن کانفیگ</h2>
        <form method="POST" action="">
            <input type="text" name="config_name" placeholder="نام کانفیگ" required>
            <textarea name="config_settings" placeholder="تنظیمات کانفیگ" required></textarea>
            <div class="form-group">
                <label for="port_type">نوع پورت:</label>
                <select name="port_type" id="port_type" required>
                    <option value="single_port">تک پورت</option>
                    <option value="multi_port">مولتی پورت</option>
                </select>
            </div>
            <div class="form-group">
                <label for="server_ids">انتخاب سرورها:</label>
                <select name="server_ids[]" id="server_ids" multiple required>
                    <?php foreach ($servers as $server): ?>
                        <option value="<?= $server['id'] ?>"><?= htmlspecialchars($server['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="add_config">ذخیره</button>
            <button type="button" onclick="closePopup()">بستن</button>
        </form>
    </div>

    <!-- پاپ‌آپ برای ویرایش کانفیگ -->
    <div class="overlay" id="editOverlay"></div>
    <div class="popup" id="editPopup">
        <h2>ویرایش کانفیگ</h2>
        <form method="POST" action="">
            <input type="hidden" name="edit_config" value="1">
            <input type="hidden" name="config_id" id="edit_config_id">
            <input type="text" id="edit_config_name" name="config_name" placeholder="نام کانفیگ" required>
            <textarea id="edit_config_settings" name="config_settings" placeholder="تنظیمات کانفیگ" required></textarea>
            <div class="form-group">
                <label for="edit_port_type">نوع پورت:</label>
                <select id="edit_port_type" name="port_type" required>
                    <option value="single_port">تک پورت</option>
                    <option value="multi_port">مولتی پورت</option>
                </select>
            </div>
            <div class="form-group">
                <label for="edit_server_ids">انتخاب سرورها:</label>
                <select id="edit_server_ids" name="server_ids[]" multiple required>
                    <?php foreach ($servers as $server): ?>
                        <option value="<?= $server['id'] ?>"><?= htmlspecialchars($server['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="edit_config">ذخیره</button>
            <button type="button" onclick="closeEditPopup()">بستن</button>
        </form>
    </div>

    <script>
        function openPopup() {
            document.getElementById('popup').style.display = 'block';
            document.getElementById('overlay').style.display = 'block';
        }

        function closePopup() {
            document.getElementById('popup').style.display = 'none';
            document.getElementById('overlay').style.display = 'none';
        }

        function openEditPopup(configId, configName, configSettings, portType, serverIds) {
            document.getElementById('edit_config_id').value = configId;
            document.getElementById('edit_config_name').value = configName;
            document.getElementById('edit_config_settings').value = configSettings;
            document.getElementById('edit_port_type').value = portType;

            const serverSelect = document.getElementById('edit_server_ids');
            Array.from(serverSelect.options).forEach(option => {
                option.selected = serverIds.includes(parseInt(option.value));
            });

            document.getElementById('editPopup').style.display = 'block';
            document.getElementById('editOverlay').style.display = 'block';
        }

        function closeEditPopup() {
            document.getElementById('editPopup').style.display = 'none';
            document.getElementById('editOverlay').style.display = 'none';
        }

        function deleteConfig(configId) {
            if (confirm('آیا از حذف این کانفیگ مطمئن هستید؟')) {
                window.location.href = '?page=addconfig&delete_config=' + configId;
            }
        }
    </script>
</body>
</html>