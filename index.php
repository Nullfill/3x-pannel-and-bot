<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// اطمینان از اینکه هیچ خروجی قبل از شروع سشن وجود ندارد
if (ob_get_length()) ob_end_clean();

// شروع سشن و لاگ کردن وضعیت آن
session_start();
error_log("Session in index.php: " . print_r($_SESSION, true));
error_log("Session ID in index.php: " . session_id());
error_log("Session save path in index.php: " . ini_get('session.save_path'));

require_once 'config/auth.php';
require_once 'config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'لطفا نام کاربری و رمز عبور را وارد کنید';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, password FROM panel_admins WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                loginUser($user['id']);
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $error = 'نام کاربری یا رمز عبور اشتباه است';
            }
        } catch (Exception $e) {
            $error = 'خطا در ورود به سیستم: ' . $e->getMessage();
        }
    }
}

// اگر کاربر لاگین نکرده، فرم لاگین را نمایش بده
if (!isLoggedIn()) {
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ورود به پنل مدیریت</title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap');
            
            :root {
                --primary-color: #4318FF;
                --primary-light: #9f87ff;
            }
            
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Vazirmatn', Tahoma, Arial, sans-serif;
            }
            
            body {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
                padding: 20px;
            }
            
            .login-container {
                background: white;
                padding: 40px;
                border-radius: 20px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                width: 100%;
                max-width: 400px;
                animation: slideUp 0.5s ease-out;
            }
            
            @keyframes slideUp {
                from {
                    transform: translateY(20px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
            
            .login-header {
                text-align: center;
                margin-bottom: 30px;
            }
            
            .login-header h1 {
                color: var(--primary-color);
                font-size: 24px;
                margin-bottom: 10px;
            }
            
            .login-header i {
                font-size: 48px;
                color: var(--primary-color);
                margin-bottom: 20px;
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
            
            .form-group input {
                width: 100%;
                padding: 12px 15px;
                border: 2px solid #e2e8f0;
                border-radius: 12px;
                font-size: 16px;
                transition: all 0.3s;
            }
            
            .form-group input:focus {
                border-color: var(--primary-color);
                outline: none;
                box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.1);
            }
            
            button {
                width: 100%;
                padding: 12px;
                background: linear-gradient(90deg, var(--primary-color), var(--primary-light));
                border: none;
                border-radius: 12px;
                color: white;
                font-size: 16px;
                font-weight: 500;
                cursor: pointer;
                transition: transform 0.3s, box-shadow 0.3s;
            }
            
            button:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(67, 24, 255, 0.2);
            }
            
            .error-message {
                background: #FEE2E2;
                color: #991B1B;
                padding: 12px;
                border-radius: 12px;
                margin-bottom: 20px;
                text-align: center;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="login-header">
                <i class="fas fa-user-shield"></i>
                <h1>ورود به پنل مدیریت</h1>
            </div>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">نام کاربری</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">رمز عبور</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit">ورود به سیستم</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// تعیین صفحه فعلی بر اساس پارامتر GET
$current_page = $_GET['page'] ?? 'server-login';

// اگر کاربر لاگین کرده، محتوای اصلی صفحه را نمایش بده
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap');

        :root {
            --primary-color: #4318FF;
            --primary-light: #9f87ff;
            --text-dark: #2B3674;
            --bg-light: #f0f2f5;
            --white: #ffffff;
            --sidebar-width: 280px;
            --header-height: 70px;
            --transition: all 0.3s ease-in-out;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Vazirmatn', Tahoma, Arial, sans-serif;
        }

        body {
            background-color: var(--bg-light);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .container {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* Bottom Navigation */
        .bottom-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            padding: 10px 0;
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
        }

        .bottom-nav .menu {
            display: flex;
            justify-content: space-around;
            align-items: center;
            padding: 0;
        }

        .bottom-nav .menu-item {
            flex: 1;
            padding: 8px 0;
            margin: 0;
            flex-direction: column;
            background: none;
            color: var(--text-dark);
            font-size: 12px;
            text-align: center;
            border-radius: 0;
        }

        .bottom-nav .menu-item i {
            margin: 0 0 5px 0;
            font-size: 20px;
            width: auto;
            color: #666;
        }

        .bottom-nav .menu-item:hover,
        .bottom-nav .menu-item.active {
            background: none;
            transform: none;
        }

        .bottom-nav .menu-item:hover i,
        .bottom-nav .menu-item.active i,
        .bottom-nav .menu-item:hover,
        .bottom-nav .menu-item.active {
            color: var(--primary-color);
        }

        .bottom-nav .menu-item.active {
            position: relative;
        }

        .bottom-nav .menu-item.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background-color: var(--primary-color);
        }

        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: var(--white);
            position: fixed;
            height: 100vh;
            transition: var(--transition);
            right: 0;
            top: 0;
            z-index: 1000;
            padding-top: 20px;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            margin-bottom: 30px;
        }

        .sidebar-header h2 {
            color: var(--white);
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .menu {
            padding: 0 15px;
        }

        .menu-item {
            padding: 15px 25px;
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: var(--transition);
            border-radius: 15px;
            margin-bottom: 10px;
        }

        .menu-item:hover, 
        .menu-item.active {
            background: rgba(255, 255, 255, 0.2);
            color: var(--white);
            transform: translateX(-5px);
        }

        .menu-item i {
            margin-left: 15px;
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        .logout {
            position: sticky;
            bottom: 20px;
            width: calc(100% - 30px);
            margin: 15px;
            padding: 15px 25px;
            color: var(--white);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: var(--transition);
            border-radius: 15px;
            background: rgba(255, 255, 255, 0.1);
        }

        .logout:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(-5px);
        }

        .logout i {
            margin-left: 15px;
            width: 20px;
            text-align: center;
        }

        .main-content {
            margin-right: var(--sidebar-width);
            flex: 1;
            padding: 30px;
            background-color: var(--bg-light);
            min-height: 100vh;
            width: calc(100% - var(--sidebar-width));
            position: relative;
        }

        .content-header {
            margin-bottom: 30px;
        }

        .content-header h1 {
            color: var(--text-dark);
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .card {
            background: var(--white);
            border-radius: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            padding: 25px;
            margin-bottom: 25px;
            transition: var(--transition);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .card h2 {
            color: var(--text-dark);
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: var(--text-dark);
            font-weight: 500;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            transition: var(--transition);
            background: var(--white);
        }

        .form-group input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.1);
        }

        button {
            background: linear-gradient(90deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: var(--white);
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            font-size: 16px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 24, 255, 0.2);
        }

        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .success {
            background-color: #E6F6F0;
            color: #047857;
            border: 1px solid #A7F3D0;
        }

        .error {
            background-color: #FEE2E2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -15px;
            padding: 0 15px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
            background-color: transparent;
        }

        .table th,
        .table td {
            padding: 1rem;
            text-align: right;
            border-bottom: 1px solid #e0e5f2;
        }

        .table th {
            font-weight: 600;
            background-color: rgba(67, 24, 255, 0.05);
        }

        .table tbody tr:hover {
            background-color: rgba(67, 24, 255, 0.02);
        }

        .btn-edit,
        .btn-delete {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 35px;
            height: 35px;
            border-radius: 8px;
            margin: 0 5px;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn-edit {
            background-color: rgba(67, 24, 255, 0.1);
            color: var(--primary-color);
        }

        .btn-delete {
            background-color: rgba(220, 38, 38, 0.1);
            color: #dc2626;
        }

        .btn-edit:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-delete:hover {
            background-color: #dc2626;
            color: white;
        }

        @media (max-width: 1024px) {
            :root {
                --sidebar-width: 240px;
            }
        }

        @media (max-width: 768px) {
            :root {
                --sidebar-width: 0;
            }

            .mobile-header {
                display: flex;
            }

            .sidebar {
                display: none;
            }

            .bottom-nav {
                display: block;
            }
            
            .main-content {
                margin-right: 0;
                padding: 20px;
                padding-bottom: 80px;
                width: 100%;
            }

            .container {
                width: 100%;
            }

            .table-responsive {
                margin: 0 -15px;
                padding: 0 15px;
                width: calc(100% + 30px);
            }

            .table th,
            .table td {
                padding: 0.75rem;
                font-size: 14px;
            }

            .btn-edit,
            .btn-delete {
                width: 30px;
                height: 30px;
            }
        }

        @media (max-width: 480px) {
            .content-header h1 {
                font-size: 22px;
            }
            
            .card h2 {
                font-size: 18px;
            }
            
            .menu-item {
                padding: 10px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>پنل مدیریت</h2>
            </div>
            <div class="menu">
                <a href="?page=server-login" class="menu-item <?php echo $current_page === 'server-login' ? 'active' : ''; ?>">
                    <i class="fas fa-server"></i>
                    افزودن و ورود به سرور
                </a>
                <a href="?page=addconfig" class="menu-item <?php echo $current_page === 'addconfig' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    افزودن کانفیگ
                </a>
                <a href="?page=addproduct" class="menu-item <?php echo $current_page === 'addproduct' ? 'active' : ''; ?>">
                    <i class="fas fa-box"></i>
                    افزودن محصول 
                </a>
                <a href="?page=users" class="menu-item <?php echo $current_page === 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    مدیریت کاربران
                </a>
                <a href="?page=settings" class="menu-item <?php echo $current_page === 'settings' ? 'active' : ''; ?>">
                    <i class="fas fa-sliders-h"></i>
                    تنظیمات 
                </a>
                <a href="?page=financial" class="menu-item <?php echo $current_page === 'financial' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    گزارش مالی
                </a>
                <a href="?page=broadcast" class="menu-item <?php echo $current_page === 'broadcast' ? 'active' : ''; ?>">
                    <i class="fas fa-bullhorn"></i>
                    پیام سراسری
                </a>
            </div>
            <a href="?page=logout" class="logout">
                <i class="fas fa-sign-out-alt"></i>
                خروج
            </a>
        </div>

        <!-- Bottom Navigation -->
        <nav class="bottom-nav">
            <div class="menu">
                <a href="?page=server-login" class="menu-item <?php echo $current_page === 'server-login' ? 'active' : ''; ?>">
                    <i class="fas fa-server"></i>
                    سرور
                </a>
                <a href="?page=addconfig" class="menu-item <?php echo $current_page === 'addconfig' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    کانفیگ
                </a>
                <a href="?page=addproduct" class="menu-item <?php echo $current_page === 'addproduct' ? 'active' : ''; ?>">
                    <i class="fas fa-box"></i>
                    محصول
                </a>
                <a href="?page=users" class="menu-item <?php echo $current_page === 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    کاربران
                </a>
                <a href="?page=settings" class="menu-item <?php echo $current_page === 'settings' ? 'active' : ''; ?>">
                    <i class="fas fa-sliders-h"></i>
                    تنظیمات
                </a>
                <a href="?page=broadcast" class="menu-item <?php echo $current_page === 'broadcast' ? 'active' : ''; ?>">
                    <i class="fas fa-bullhorn"></i>
                    پیام
                </a>
            </div>
        </nav>

        <!-- Main Content -->
        <div class="main-content">
            <?php
            // بارگذاری صفحه مورد نظر
            $page_file = "pages/{$current_page}.php";
            if (file_exists($page_file)) {
                include $page_file;
            } else {
                echo '<div class="alert error">صفحه مورد نظر یافت نشد.</div>';
            }
            ?>
        </div>
    </div>
</body>
</html>