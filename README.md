# پنل مدیریت 3x-UI با ربات تلگرام

یک پنل وب برای مدیریت چند سرور X-UI همراه با ربات تلگرام برای مدیریت کاربران، سرویس‌ها و فروش کانفیگ. این پروژه را به‌عنوان ابزار شخصی/قابل توسعه برای کار با Xray و چند سرور نگه می‌دارم.

![تصویر پنل اول](https://raw.githubusercontent.com/Nullfill/3x-pannel-and-bot/main/images/screenshot%201.png)
![تصویر پنل دوم](https://raw.githubusercontent.com/Nullfill/3x-pannel-and-bot/main/images/screenshot%202.png)

## قابلیت‌ها

- مدیریت چند سرور X-UI
- مدیریت محصولات، کاربران و گزارش‌های مالی
- رابط کاربری واکنش‌گرا
- ربات تلگرام برای فروش سرویس، کیف پول و تحویل کانفیگ
- پشتیبانی از MySQL / MariaDB

## پیش‌نیازها

- PHP 7.4 یا بالاتر
- Apache یا Nginx
- MySQL یا MariaDB

## نصب

پروژه را در مسیر وب‌سرور قرار دهید و یک دیتابیس و کاربر دیتابیس ایجاد کنید.

تنظیمات حساس دیگر نباید داخل سورس قرار بگیرند. متغیرهای محیطی زیر را روی سرور تنظیم کنید:

```bash
export DB_HOST=localhost
export DB_NAME=your_database
export DB_USER=your_database_user
export DB_PASS='your-strong-password'
export PANEL_ADMIN_PASSWORD='your-strong-admin-password'
export TELEGRAM_BOT_TOKEN='your-telegram-bot-token'
```

نمونه تنظیمات در فایل `.env.example` موجود است. توجه کنید که خود فایل `.env` در `.gitignore` قرار دارد و نباید commit شود.

در اولین اجرا، جداول دیتابیس ساخته می‌شوند و کاربر `admin` با مقدار `PANEL_ADMIN_PASSWORD` ایجاد می‌شود.

## راه‌اندازی ربات تلگرام

پس از ساخت ربات در BotFather، مقدار توکن را در `TELEGRAM_BOT_TOKEN` تنظیم کنید و سپس webhook را به مسیر زیر متصل کنید:

```text
https://YOUR_DOMAIN_AND_PATH/pages/tel/index.php
```

توکن واقعی، رمز دیتابیس یا رمز ادمین را داخل فایل‌های پروژه commit نکنید.

## نکته امنیتی

اگر قبلاً credential واقعی در تاریخچه Git این repository قرار گرفته، حذف آن از فایل فعلی کافی نیست. credential مربوطه را rotate کنید و در صورت نیاز تاریخچه Git را نیز پاک‌سازی کنید.

## مشارکت

Pull Request و Issue پذیرفته می‌شود.
