<div class="content-header">
    <h1>تنظیمات</h1>
</div>

<div class="card">
    <h2>تنظیمات عمومی</h2>
    <div class="settings-grid">
        <div class="settings-item">
            <div class="settings-icon">
                <i class="fas fa-globe"></i>
            </div>
            <div class="settings-content">
                <h3>تنظیمات سایت</h3>
                <p>تنظیمات مربوط به وبسایت و نمایش</p>
                <button onclick="alert('این بخش در حال توسعه است')">
                    <i class="fas fa-cog"></i>
                    تنظیم
                </button>
            </div>
        </div>
        
        <div class="settings-item">
            <div class="settings-icon">
                <i class="fas fa-user-shield"></i>
            </div>
            <div class="settings-content">
                <h3>امنیت</h3>
                <p>تنظیمات امنیتی و دسترسی‌ها</p>
                <button onclick="alert('این بخش در حال توسعه است')">
                    <i class="fas fa-cog"></i>
                    تنظیم
                </button>
            </div>
        </div>

        <div class="settings-item">
            <div class="settings-icon">
                <i class="fas fa-bell"></i>
            </div>
            <div class="settings-content">
                <h3>اعلان‌ها</h3>
                <p>تنظیمات مربوط به اعلان‌ها و هشدارها</p>
                <button onclick="alert('این بخش در حال توسعه است')">
                    <i class="fas fa-cog"></i>
                    تنظیم
                </button>
            </div>
        </div>

        <div class="settings-item">
            <div class="settings-icon">
                <i class="fas fa-wallet"></i>
            </div>
            <div class="settings-content">
                <h3>پرداخت</h3>
                <p>تنظیمات درگاه‌های پرداخت</p>
                <button onclick="alert('این بخش در حال توسعه است')">
                    <i class="fas fa-cog"></i>
                    تنظیم
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .settings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 25px;
        margin-top: 30px;
    }

    .settings-item {
        background: white;
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
        display: flex;
        gap: 20px;
    }

    .settings-item:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
    }

    .settings-icon {
        width: 60px;
        height: 60px;
        background: linear-gradient(45deg, #4318FF, #9f87ff);
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .settings-icon i {
        font-size: 24px;
        color: white;
    }

    .settings-content {
        flex-grow: 1;
    }

    .settings-content h3 {
        color: #2B3674;
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 10px;
    }

    .settings-content p {
        color: #707EAE;
        font-size: 14px;
        line-height: 1.6;
        margin-bottom: 15px;
    }

    button {
        background: linear-gradient(90deg, #4318FF 0%, #9f87ff 100%);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 12px;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    button:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(67, 24, 255, 0.2);
    }

    button i {
        font-size: 14px;
    }

    @media (max-width: 768px) {
        .settings-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .settings-item {
            padding: 20px;
        }

        .settings-icon {
            width: 50px;
            height: 50px;
        }

        .settings-icon i {
            font-size: 20px;
        }

        button {
            width: 100%;
            justify-content: center;
        }
    }
</style>