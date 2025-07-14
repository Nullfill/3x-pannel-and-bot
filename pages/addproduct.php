<?php
// دریافت تنظیمات دیتابیس از فایل config.php
require_once 'config.php';

// اتصال به دیتابیس
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// بررسی خطای اتصال
if ($conn->connect_error) {
    die("خطا در اتصال به دیتابیس: " . $conn->connect_error);
}

// بررسی وجود جدول products و ایجاد آن در صورت عدم وجود
$table_check_query = "SHOW TABLES LIKE 'products'";
$result = $conn->query($table_check_query);

if ($result === FALSE) {
    die("خطا در بررسی وجود جدول products: " . $conn->error);
}

if ($result->num_rows == 0) {
    // اگر جدول products وجود نداشت، آن را ایجاد کنید
    $create_table_query = "CREATE TABLE products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_name VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        config_ids TEXT NOT NULL,
        price DECIMAL(10, 2) DEFAULT 0.00,
        volume_gb INT DEFAULT 0,
        days_count INT DEFAULT 0, -- اضافه کردن فیلد days_count
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    if ($conn->query($create_table_query) === TRUE) {
        echo '<div class="alert success">جدول products با موفقیت ایجاد شد.</div>';
    } else {
        die("خطا در ایجاد جدول products: " . $conn->error);
    }
} else {
    // بررسی وجود فیلدهای price, volume_gb و days_count و اضافه کردن آنها در صورت عدم وجود
    $check_price_field = $conn->query("SHOW COLUMNS FROM products LIKE 'price'");
    if ($check_price_field->num_rows == 0) {
        $conn->query("ALTER TABLE products ADD COLUMN price DECIMAL(10, 2) DEFAULT 0.00");
    }

    $check_volume_gb_field = $conn->query("SHOW COLUMNS FROM products LIKE 'volume_gb'");
    if ($check_volume_gb_field->num_rows == 0) {
        $conn->query("ALTER TABLE products ADD COLUMN volume_gb INT DEFAULT 0");
    }

    $check_days_count_field = $conn->query("SHOW COLUMNS FROM products LIKE 'days_count'");
    if ($check_days_count_field->num_rows == 0) {
        $conn->query("ALTER TABLE products ADD COLUMN days_count INT DEFAULT 0");
    }
}

// خواندن کانفیگ‌های ذخیره شده از دیتابیس
$configs = [];
$result = $conn->query("SELECT * FROM configs");

if ($result === FALSE) {
    die("خطا در خواندن کانفیگ‌ها از دیتابیس: " . $conn->error);
}

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $configs[] = $row;
    }
} else {
    echo '<div class="alert info">هیچ کانفیگی در دیتابیس وجود ندارد.</div>';
}

// ذخیره محصول جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $product_name = $_POST['product_name'] ?? '';
    $description = $_POST['description'] ?? '';
    $config_ids = $_POST['config_ids'] ?? [];
    $price = floatval($_POST['price'] ?? 0.00);
    $volume_gb = intval($_POST['volume_gb'] ?? 0);
    $days_count = intval($_POST['days_count'] ?? 0); // دریافت مقدار days_count

    if (!empty($product_name) && !empty($description) && !empty($config_ids)) {
        $config_ids_str = implode(',', $config_ids);

        $stmt = $conn->prepare("INSERT INTO products (product_name, description, config_ids, price, volume_gb, days_count) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssdii", $product_name, $description, $config_ids_str, $price, $volume_gb, $days_count);

        if ($stmt->execute()) {
            echo '<div class="alert success">محصول با موفقیت ذخیره شد.</div>';
        } else {
            echo '<div class="alert error">خطا در ذخیره محصول: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    } else {
        echo '<div class="alert error">لطفا تمام فیلدها را پر کنید و حداقل یک کانفیگ انتخاب کنید.</div>';
    }
}

// ویرایش محصول
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_product'])) {
    $product_id = intval($_POST['product_id']);
    $product_name = $_POST['product_name'] ?? '';
    $description = $_POST['description'] ?? '';
    $config_ids = $_POST['config_ids'] ?? [];
    $price = floatval($_POST['price'] ?? 0.00);
    $volume_gb = intval($_POST['volume_gb'] ?? 0);
    $days_count = intval($_POST['days_count'] ?? 0); // دریافت مقدار days_count

    if (!empty($product_name) && !empty($description) && !empty($config_ids)) {
        $config_ids_str = implode(',', $config_ids);

        $stmt = $conn->prepare("UPDATE products SET product_name = ?, description = ?, config_ids = ?, price = ?, volume_gb = ?, days_count = ? WHERE id = ?");
        $stmt->bind_param("sssdiii", $product_name, $description, $config_ids_str, $price, $volume_gb, $days_count, $product_id);

        if ($stmt->execute()) {
            echo '<div class="alert success">محصول با موفقیت ویرایش شد.</div>';
        } else {
            echo '<div class="alert error">خطا در ویرایش محصول: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    } else {
        echo '<div class="alert error">لطفا تمام فیلدها را پر کنید و حداقل یک کانفیگ انتخاب کنید.</div>';
    }
}

// حذف محصول
if (isset($_GET['delete_product'])) {
    $product_id = intval($_GET['delete_product']);
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);

    if ($stmt->execute()) {
        echo '<div class="alert success">محصول با موفقیت حذف شد.</div>';
    } else {
        echo '<div class="alert error">خطا در حذف محصول: ' . $stmt->error . '</div>';
    }
    $stmt->close();
}

// خواندن محصولات ذخیره شده از دیتابیس
$products = [];
$result = $conn->query("SELECT * FROM products");

if ($result === FALSE) {
    die("خطا در خواندن محصولات از دیتابیس: " . $conn->error);
}

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
}

$conn->close();
?>

<div class="content-header">
    <h1>مدیریت محصولات</h1>
</div>

<div class="card">
    <h2>افزودن محصول جدید</h2>
    <form method="POST" action="">
        <div class="form-group">
            <label for="product_name">نام محصول</label>
            <input type="text" id="product_name" name="product_name" placeholder="نام محصول را وارد کنید" required>
        </div>
        <div class="form-group">
            <label for="description">توضیحات محصول</label>
            <textarea id="description" name="description" placeholder="توضیحات محصول را وارد کنید" required></textarea>
        </div>
        <div class="form-group">
            <label for="price">قیمت</label>
            <input type="number" step="0.01" id="price" name="price" placeholder="قیمت را وارد کنید" required>
        </div>
        <div class="form-group">
            <label for="volume_gb">حجم گیگابایت</label>
            <input type="number" id="volume_gb" name="volume_gb" placeholder="حجم را به گیگابایت وارد کنید" required>
        </div>
        <div class="form-group">
            <label for="days_count">تعداد روز</label>
            <input type="number" id="days_count" name="days_count" placeholder="تعداد روز را وارد کنید" required>
        </div>
        <div class="form-group">
            <label for="config_ids">انتخاب کانفیگ‌ها</label>
            <select name="config_ids[]" id="config_ids" multiple required>
                <?php foreach ($configs as $config): ?>
                    <option value="<?= $config['id'] ?>"><?= htmlspecialchars($config['config_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" name="add_product">
            <i class="fas fa-plus"></i>
            افزودن محصول
        </button>
    </form>
</div>

<div class="card">
    <h2>لیست محصولات</h2>
    <div class="product-grid">
        <?php if (!empty($products)): ?>
            <?php foreach ($products as $product): ?>
                <div class="product-item">
                    <h3><?= htmlspecialchars($product['product_name']) ?></h3>
                    <p><i class="fas fa-align-left"></i> <?= htmlspecialchars($product['description']) ?></p>
                    <p><i class="fas fa-tag"></i> قیمت: <?= htmlspecialchars($product['price']) ?></p>
                    <p><i class="fas fa-database"></i> حجم: <?= htmlspecialchars($product['volume_gb']) ?> GB</p>
                    <p><i class="fas fa-calendar-alt"></i> مدت: <?= htmlspecialchars($product['days_count']) ?> روز</p>
                    <p><i class="fas fa-cog"></i> کانفیگ‌ها: <?= htmlspecialchars($product['config_ids']) ?></p>
                    <div class="product-actions">
                        <button class="edit" onclick="openEditPopup(<?= $product['id'] ?>, '<?= htmlspecialchars($product['product_name']) ?>', '<?= htmlspecialchars($product['description']) ?>', [<?= $product['config_ids'] ?>], <?= $product['price'] ?>, <?= $product['volume_gb'] ?>, <?= $product['days_count'] ?>)">
                            <i class="fas fa-edit"></i> ویرایش
                        </button>
                        <button class="delete" onclick="deleteProduct(<?= $product['id'] ?>)">
                            <i class="fas fa-trash-alt"></i> حذف
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert info">هیچ محصولی در دیتابیس وجود ندارد.</div>
        <?php endif; ?>
    </div>
</div>

<!-- پاپ‌آپ ویرایش محصول -->
<div class="overlay" id="overlay"></div>
<div class="popup" id="editPopup">
    <h2>ویرایش محصول</h2>
    <form method="POST" action="">
        <input type="hidden" name="product_id" id="edit_product_id">
        <div class="form-group">
            <label for="edit_product_name">نام محصول</label>
            <input type="text" id="edit_product_name" name="product_name" required>
        </div>
        <div class="form-group">
            <label for="edit_description">توضیحات محصول</label>
            <textarea id="edit_description" name="description" required></textarea>
        </div>
        <div class="form-group">
            <label for="edit_price">قیمت</label>
            <input type="number" step="0.01" id="edit_price" name="price" required>
        </div>
        <div class="form-group">
            <label for="edit_volume_gb">حجم گیگابایت</label>
            <input type="number" id="edit_volume_gb" name="volume_gb" required>
        </div>
        <div class="form-group">
            <label for="edit_days_count">تعداد روز</label>
            <input type="number" id="edit_days_count" name="days_count" required>
        </div>
        <div class="form-group">
            <label for="edit_config_ids">انتخاب کانفیگ‌ها</label>
            <select name="config_ids[]" id="edit_config_ids" multiple required>
                <?php foreach ($configs as $config): ?>
                    <option value="<?= $config['id'] ?>"><?= htmlspecialchars($config['config_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" name="edit_product">
            <i class="fas fa-save"></i>
            ذخیره تغییرات
        </button>
        <button type="button" onclick="closeEditPopup()" style="background: #E0E5F2; color: #2B3674; margin-right: 10px;">
            <i class="fas fa-times"></i>
            بستن
        </button>
    </form>
</div>

<style>
    .product-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 25px;
        margin-top: 30px;
    }

    .product-item {
        background: white;
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
    }

    .product-item:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    }

    .product-item h3 {
        color: #2B3674;
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 15px;
    }

    .product-item p {
        color: #707EAE;
        margin: 10px 0;
        font-size: 14px;
        line-height: 1.6;
    }

    .product-item p i {
        width: 20px;
        margin-left: 8px;
        color: #4318FF;
    }

    .product-actions {
        display: flex;
        gap: 10px;
        margin-top: 20px;
    }

    .product-actions button {
        flex: 1;
        padding: 10px;
        font-size: 14px;
    }

    .product-actions button.edit {
        background: linear-gradient(90deg, #FFB547 0%, #FFD574 100%);
    }

    .product-actions button.delete {
        background: linear-gradient(90deg, #FF5B5B 0%, #FF8080 100%);
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

    .form-group input,
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #E0E5F2;
        border-radius: 15px;
        font-size: 14px;
        transition: all 0.3s ease;
        background: white;
    }

    .form-group textarea {
        min-height: 120px;
        resize: vertical;
    }

    .form-group select[multiple] {
        height: 150px;
    }

    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
        border-color: #4318FF;
        box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.1);
        outline: none;
    }

    .popup {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        border-radius: 20px;
        box-shadow: 0 5px 25px rgba(0,0,0,0.1);
        padding: 30px;
        width: 90%;
        max-width: 600px;
        z-index: 1001;
    }

    .popup h2 {
        color: #2B3674;
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 25px;
    }

    .overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(5px);
        z-index: 1000;
    }

    button {
        background: linear-gradient(90deg, #4318FF 0%, #9f87ff 100%);
        color: white;
        border: none;
        padding: 12px 25px;
        border-radius: 15px;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 10px;
    }

    button:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(67, 24, 255, 0.2);
    }

    button i {
        font-size: 16px;
    }

    @media (max-width: 768px) {
        .product-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .popup {
            width: 95%;
            padding: 20px;
        }

        .form-group select[multiple] {
            height: 120px;
        }

        button {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<script>
    function openEditPopup(id, name, description, configIds, price, volume_gb, days_count) {
        document.getElementById('edit_product_id').value = id;
        document.getElementById('edit_product_name').value = name;
        document.getElementById('edit_description').value = description;
        document.getElementById('edit_price').value = price;
        document.getElementById('edit_volume_gb').value = volume_gb;
        document.getElementById('edit_days_count').value = days_count;

        const configSelect = document.getElementById('edit_config_ids');
        Array.from(configSelect.options).forEach(option => {
            option.selected = configIds.includes(option.value);
        });

        document.getElementById('editPopup').style.display = 'block';
        document.getElementById('overlay').style.display = 'block';
    }

    function closeEditPopup() {
        document.getElementById('editPopup').style.display = 'none';
        document.getElementById('overlay').style.display = 'none';
    }

    function deleteProduct(id) {
        if (confirm('آیا از حذف این محصول مطمئن هستید؟')) {
            window.location.href = '?page=addproduct&delete_product=' + id;
        }
    }
</script>