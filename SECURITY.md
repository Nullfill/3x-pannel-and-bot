# راهنمای امنیتی پروژه

این سند شامل توصیه‌های امنیتی برای نصب و استفاده از این پروژه است.

## 🔐 تنظیمات اولیه امنیتی

### 1. پیکربندی فایل config.php

**مهم:** فایل `pages/config.php` حاوی اطلاعات حساس است.

```bash
# ابتدا فایل نمونه را کپی کنید
cp pages/config.example.php pages/config.php

# سپس آن را ویرایش کنید
nano pages/config.php
```

**موارد الزامی:**
- توکن ربات تلگرام را از @BotFather دریافت و جایگزین کنید
- اطلاعات اتصال به دیتابیس را با مقادیر واقعی پر کنید
- از رمزهای عبور قوی استفاده کنید (حداقل 12 کاراکتر، شامل حروف، اعداد و نمادها)

### 2. تغییر رمز عبور پیش‌فرض

رمز عبور پیش‌فرض ادمین `141512` است که **باید حتماً** بعد از اولین ورود تغییر کند.

**مراحل تغییر:**
1. با نام کاربری `admin` و رمز `141512` وارد شوید
2. به بخش تنظیمات بروید
3. رمز عبور جدید قوی تعیین کنید

### 3. محافظت از فایل config.php

اطمینان حاصل کنید که فایل config.php در Git کامیت نشده است:

```bash
# بررسی وضعیت Git
git status

# اگر config.php در لیست است، آن را ignore کنید
echo "pages/config.php" >> .gitignore
```

## 🛡️ توصیه‌های امنیتی سرور

### 1. تنظیمات PHP

در فایل `php.ini` یا `.htaccess`:

```ini
# غیرفعال کردن نمایش خطاها در محیط Production
display_errors = Off
log_errors = On

# محدود کردن اندازه آپلود
upload_max_filesize = 5M
post_max_size = 5M

# تنظیمات Session امن
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1
```

### 2. مجوزهای فایل

```bash
# تنظیم مجوزهای مناسب
chmod 644 pages/config.php
chmod 755 pages/
chown www-data:www-data -R /path/to/project
```

### 3. استفاده از HTTPS

**الزامی:** برای امنیت ارتباطات، حتماً از HTTPS استفاده کنید.

```bash
# نصب Let's Encrypt
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d yourdomain.com
```

## 🔒 امنیت دیتابیس

### 1. ایجاد کاربر اختصاصی

به جای استفاده از root، یک کاربر اختصاصی ایجاد کنید:

```sql
CREATE USER 'vpn_panel_user'@'localhost' IDENTIFIED BY 'StrongPassword123!@#';
GRANT SELECT, INSERT, UPDATE, DELETE ON vpn_database.* TO 'vpn_panel_user'@'localhost';
FLUSH PRIVILEGES;
```

### 2. Backup منظم

```bash
# اسکریپت پشتیبان‌گیری روزانه
#!/bin/bash
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql
```

## ⚠️ مشکلات امنیتی شناخته شده (قبل از اصلاح)

مشکلات زیر در نسخه‌های قبلی شناسایی و **اصلاح شده‌اند**:

### ✅ اصلاح شده: SQL Injection در financial.php
- **قبل:** استفاده مستقیم از پارامترهای GET در کوئری‌ها
- **بعد:** استفاده از Prepared Statements و اعتبارسنجی تاریخ

### ✅ اصلاح شده: افشای اطلاعات حساس
- **قبل:** ذخیره رمز عبور در فایل `pages/tel/utils/db.php`
- **بعد:** استفاده از فایل مرکزی config.php

### ✅ اضافه شده: فایل .gitignore
- جلوگیری از کامیت شدن فایل‌های حساس در Git

## 📋 چک‌لیست امنیتی

قبل از استفاده در محیط Production:

- [ ] تغییر تمام رمزهای پیش‌فرض
- [ ] استفاده از HTTPS
- [ ] فعال‌سازی فایروال سرور
- [ ] محدود کردن دسترسی SSH
- [ ] تنظیم Backup خودکار
- [ ] غیرفعال کردن display_errors در PHP
- [ ] بررسی مجوزهای فایل‌ها
- [ ] فعال‌سازی rate limiting
- [ ] نصب fail2ban برای محافظت از login
- [ ] مانیتورینگ لاگ‌ها

## 🚨 گزارش مشکلات امنیتی

اگر مشکل امنیتی پیدا کردید، لطفاً از طریق Issues گزارش ندهید.  
به جای آن، مستقیماً به سازنده پروژه پیام دهید.

## 📚 منابع بیشتر

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Guide](https://www.php.net/manual/en/security.php)
- [MySQL Security Best Practices](https://dev.mysql.com/doc/refman/8.0/en/security-guidelines.html)

---

**آخرین بروزرسانی:** 2024  
**نسخه:** 1.0
