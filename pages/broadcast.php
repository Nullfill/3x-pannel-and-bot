<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/config.php'; // اگر config.php در همان پوشه pages است

if (!isLoggedIn()) {
    header("Location: index.php");
    exit();
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $message = $_POST['message'] ?? '';
    $image = $_FILES['image'] ?? null;
    
    if (empty($message) && !$image) {
        $error = 'لطفاً متن پیام یا تصویر را وارد کنید';
    } else {
        try {
            // دریافت تمام کاربران
            $stmt = $pdo->prepare("SELECT userid FROM users");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // ارسال پیام به هر کاربر
            $success_count = 0;
            $fail_count = 0;
            
            foreach ($users as $user_id) {
                try {
                    if ($image && $image['error'] == 0) {
                        // ارسال تصویر با کپشن
                        $photo = new CURLFile($image['tmp_name'], $image['type'], $image['name']);
                        $data = [
                            'chat_id' => $user_id,
                            'photo' => $photo,
                            'caption' => $message,
                            'parse_mode' => 'HTML'
                        ];
                        
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot" . TOKEN . "/sendPhoto");
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $result = curl_exec($ch);
                        curl_close($ch);
                    } else {
                        // ارسال فقط متن
                        $data = [
                            'chat_id' => $user_id,
                            'text' => $message,
                            'parse_mode' => 'HTML'
                        ];
                        
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot" . TOKEN . "/sendMessage");
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $result = curl_exec($ch);
                        curl_close($ch);
                    }
                    
                    $result = json_decode($result, true);
                    if ($result && $result['ok']) {
                        $success_count++;
                    } else {
                        $fail_count++;
                    }
                } catch (Exception $e) {
                    $fail_count++;
                }
            }
            
            $success = "پیام با موفقیت ارسال شد.\n";
            $success .= "تعداد موفق: $success_count\n";
            $success .= "تعداد ناموفق: $fail_count";
            
        } catch (Exception $e) {
            $error = 'خطا در ارسال پیام: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ارسال پیام سراسری</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .broadcast-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
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
        
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            min-height: 150px;
            resize: vertical;
        }
        
        .form-group input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
        }
        
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(90deg, #4318FF, #9f87ff);
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
        
        .success-message {
            background: #E6F6F0;
            color: #047857;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 14px;
            white-space: pre-line;
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
    <div class="broadcast-container">
        <h1 style="text-align: center; margin-bottom: 30px; color: #2B3674;">ارسال پیام سراسری</h1>
        
        <?php if ($success): ?>
            <div class="success-message">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" enctype="multipart/form-data">
            <div class="form-group">
                <label for="message">متن پیام</label>
                <textarea id="message" name="message" placeholder="متن پیام خود را وارد کنید..."></textarea>
            </div>
            
            <div class="form-group">
                <label for="image">تصویر (اختیاری)</label>
                <input type="file" id="image" name="image" accept="image/*">
            </div>
            
            <button type="submit">ارسال پیام</button>
        </form>
    </div>
</body>
</html> 