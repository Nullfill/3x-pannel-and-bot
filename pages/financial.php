<?php
require_once 'config.php';

// اتصال به دیتابیس
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// بررسی خطای اتصال
if ($conn->connect_error) {
    die("خطا در اتصال به دیتابیس: " . $conn->connect_error);
}

// دریافت پارامترهای فیلتر
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // اول ماه جاری
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // امروز
$filter_type = $_GET['filter_type'] ?? 'all'; // all, income, expense

// دریافت آمار کلی
$stats = [];

// تعداد کل کاربران
$result = $conn->query("SELECT COUNT(*) as total_users FROM users");
$stats['total_users'] = $result->fetch_assoc()['total_users'];

// مجموع موجودی کیف پول کاربران
$result = $conn->query("SELECT SUM(balance) as total_balance FROM users");
$stats['total_balance'] = $result->fetch_assoc()['total_balance'];

// درآمد کل (افزایش موجودی)
$income_query = "SELECT SUM(amount) as total_income FROM transactions 
                 WHERE type IN ('admin_add', 'bot_add') 
                 AND created_at BETWEEN '$start_date' AND '$end_date 23:59:59'";
$result = $conn->query($income_query);
$stats['total_income'] = $result->fetch_assoc()['total_income'] ?? 0;

// هزینه کل (خرید کانفیگ)
$expense_query = "SELECT SUM(amount) as total_expense FROM transactions 
                  WHERE type = 'purchase' 
                  AND created_at BETWEEN '$start_date' AND '$end_date 23:59:59'";
$result = $conn->query($expense_query);
$stats['total_expense'] = $result->fetch_assoc()['total_expense'] ?? 0;

// سود خالص
$stats['net_profit'] = $stats['total_income'] - $stats['total_expense'];

// دریافت داده‌های نمودار
$chart_data = [];
$date_query = "SELECT DATE(created_at) as date, 
               SUM(CASE WHEN type IN ('admin_add', 'bot_add') THEN amount ELSE 0 END) as income,
               SUM(CASE WHEN type = 'purchase' THEN amount ELSE 0 END) as expense
               FROM transactions 
               WHERE created_at BETWEEN '$start_date' AND '$end_date 23:59:59'
               GROUP BY DATE(created_at)
               ORDER BY date";

$result = $conn->query($date_query);
while ($row = $result->fetch_assoc()) {
    $chart_data[] = $row;
}

// دریافت تراکنش‌ها با فیلتر
$transactions_query = "SELECT t.*, u.name as user_name 
                      FROM transactions t 
                      JOIN users u ON t.user_id = u.userid 
                      WHERE t.created_at BETWEEN '$start_date' AND '$end_date 23:59:59'";

if ($filter_type !== 'all') {
    if ($filter_type === 'income') {
        $transactions_query .= " AND t.type IN ('admin_add', 'bot_add')";
    } else {
        $transactions_query .= " AND t.type = 'purchase'";
    }
}

$transactions_query .= " ORDER BY t.created_at DESC";
$result = $conn->query($transactions_query);
$transactions = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
}
?>

<div class="content-header">
    <h1>گزارش مالی</h1>
</div>

<!-- فیلترها -->
<div class="filters">
    <form method="GET" class="filter-form">
        <input type="hidden" name="page" value="financial">
        <div class="form-group">
            <label>از تاریخ:</label>
            <input type="date" name="start_date" value="<?php echo $start_date; ?>">
        </div>
        <div class="form-group">
            <label>تا تاریخ:</label>
            <input type="date" name="end_date" value="<?php echo $end_date; ?>">
        </div>
        <div class="form-group">
            <label>نوع:</label>
            <select name="filter_type">
                <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>همه</option>
                <option value="income" <?php echo $filter_type === 'income' ? 'selected' : ''; ?>>درآمد</option>
                <option value="expense" <?php echo $filter_type === 'expense' ? 'selected' : ''; ?>>هزینه</option>
            </select>
        </div>
        <button type="submit">اعمال فیلتر</button>
    </form>
</div>

<!-- آمار کلی -->
<div class="financial-stats">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-info">
            <h3>تعداد کل کاربران</h3>
            <p><?php echo number_format($stats['total_users']); ?></p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="stat-info">
            <h3>موجودی کل کیف پول</h3>
            <p><?php echo number_format($stats['total_balance']); ?> تومان</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-arrow-up"></i>
        </div>
        <div class="stat-info">
            <h3>درآمد کل</h3>
            <p><?php echo number_format($stats['total_income']); ?> تومان</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-arrow-down"></i>
        </div>
        <div class="stat-info">
            <h3>هزینه کل</h3>
            <p><?php echo number_format($stats['total_expense']); ?> تومان</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-info">
            <h3>سود خالص</h3>
            <p class="<?php echo $stats['net_profit'] >= 0 ? 'profit' : 'loss'; ?>">
                <?php echo number_format($stats['net_profit']); ?> تومان
            </p>
        </div>
    </div>
</div>

<!-- نمودار -->
<div class="chart-container">
    <canvas id="financialChart"></canvas>
</div>

<!-- جدول تراکنش‌ها -->
<div class="transactions-table">
    <h2>تراکنش‌ها</h2>
    <table>
        <thead>
            <tr>
                <th>کاربر</th>
                <th>مبلغ</th>
                <th>نوع</th>
                <th>توضیحات</th>
                <th>تاریخ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $transaction): ?>
                <tr>
                    <td><?php echo htmlspecialchars($transaction['user_name']); ?></td>
                    <td class="<?php echo in_array($transaction['type'], ['admin_add', 'bot_add']) ? 'income' : 'expense'; ?>">
                        <?php echo number_format($transaction['amount']); ?> تومان
                    </td>
                    <td>
                        <?php
                        switch($transaction['type']) {
                            case 'admin_add':
                                echo 'افزایش توسط مدیریت';
                                break;
                            case 'bot_add':
                                echo 'افزایش توسط ربات';
                                break;
                            case 'purchase':
                                echo 'خرید کانفیگ';
                                break;
                            default:
                                echo $transaction['type'];
                        }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                    <td><?php echo date('Y/m/d H:i', strtotime($transaction['created_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// داده‌های نمودار
const chartData = <?php echo json_encode($chart_data); ?>;

// تنظیمات نمودار
const ctx = document.getElementById('financialChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: chartData.map(item => item.date),
        datasets: [
            {
                label: 'درآمد',
                data: chartData.map(item => item.income),
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4
            },
            {
                label: 'هزینه',
                data: chartData.map(item => item.expense),
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                tension: 0.4
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            title: {
                display: true,
                text: 'نمودار درآمد و هزینه',
                font: {
                    size: 16
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return value.toLocaleString() + ' تومان';
                    }
                }
            }
        }
    }
});
</script>

<style>
.filters {
    background: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.filter-form {
    display: flex;
    gap: 15px;
    align-items: flex-end;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.form-group label {
    font-size: 14px;
    color: var(--text-dark);
}

.form-group input,
.form-group select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
}

button[type="submit"] {
    background: var(--primary-color);
    color: white;
    border: none;
    padding: 8px 20px;
    border-radius: 5px;
    cursor: pointer;
    transition: 0.3s;
}

button[type="submit"]:hover {
    background: var(--primary-light);
}

.financial-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
}

.stat-icon {
    width: 50px;
    height: 50px;
    background: var(--primary-light);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-left: 15px;
}

.stat-icon i {
    font-size: 24px;
    color: var(--primary-color);
}

.stat-info h3 {
    font-size: 14px;
    color: var(--text-dark);
    margin-bottom: 5px;
}

.stat-info p {
    font-size: 20px;
    font-weight: bold;
    color: var(--primary-color);
}

.profit {
    color: #28a745;
}

.loss {
    color: #dc3545;
}

.chart-container {
    background: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.transactions-table {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.transactions-table h2 {
    margin-bottom: 20px;
    color: var(--text-dark);
}

.transactions-table table {
    width: 100%;
    border-collapse: collapse;
}

.transactions-table th,
.transactions-table td {
    padding: 12px;
    text-align: right;
    border-bottom: 1px solid #eee;
}

.transactions-table th {
    background: #f8f9fa;
    font-weight: 500;
}

.transactions-table tr:hover {
    background: #f8f9fa;
}

.income {
    color: #28a745;
}

.expense {
    color: #dc3545;
}

@media (max-width: 768px) {
    .filter-form {
        flex-direction: column;
        align-items: stretch;
    }
    
    .financial-stats {
        grid-template-columns: 1fr;
    }
}
</style> 