# ایجنت پنجره‌ای تیام برای ویندوز

نسخهٔ Windows x64 از بخش Releases گیت‌هاب با عنوان Tiam Windows Agent قابل دریافت است. فایل `Maarksho-Tiam-Agent.exe` مستقل از نصب Python اجرا می‌شود. فایل‌های شما روی سیستم خودتان هستند؛ اتصال SQL از همان LAN یا VPN فعلی کامپیوتر استفاده می‌کند، به شرط خاموش‌بودن VPN مدیریت‌شده در تنظیمات تیام پنل.

۱. Microsoft ODBC Driver 18 for SQL Server را روی ویندوز نصب کنید:
https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server

۲. EXE را اجرا کنید. آدرس `https://maarksho.com/panel/tiam_inventory.php`، شناسهٔ پروژه و توکن ایجنت همان پروژه را وارد کنید.

۳. «اتصال و شروع» را بزنید؛ موفقیت اتصال در لاگ نمایش داده می‌شود. حالا از تنظیمات پروژه در پنل، تست SQL یا دریافت پیش‌نمایش را بزنید و پس از بررسی تغییرات، تأیید کنید.

۴. دکمهٔ توقف و بستن پنجره منتظر پایان درخواست جاری و تحویل نتیجه می‌مانند. هنگام اجرا پنجره باید باز باشد؛ این نسخه سرویس پس‌زمینه یا اجرای خودکار بعد از روشن‌شدن ویندوز نیست. برای پروژهٔ دیگر، اتصال را متوقف و شناسه/توکن را عوض کنید.

فقط آدرس و شناسهٔ پروژه در `%APPDATA%\Maarksho\TiamAgent\settings.json` ذخیره می‌شوند؛ توکن هر بار باید وارد شود. رمز SQL از تنظیمات پروژه دریافت می‌شود و در فرم یا لاگ چاپ نمی‌شود. EXE فعلاً امضای تجاری ندارد و ممکن است SmartScreen ناشر را ناشناخته نشان دهد.

## اجرای روش قبلی در PowerShell

```powershell
$env:TIAM_PANEL_URL = "https://maarksho.com/panel/tiam_inventory.php"
$env:TIAM_PROJECT_ID = "شناسه پروژه خودتان"
$env:TIAM_AGENT_TOKEN = [System.Net.NetworkCredential]::new("", (Read-Host "Agent token" -AsSecureString)).Password
python .\tiam_inventory_agent.py
```

## ساخت دستی EXE روی ویندوز، از ریشهٔ مخزن

```powershell
python -m pip install pyinstaller==6.16.0 pyodbc==5.2.0
python -m PyInstaller --noconfirm --clean --onefile --windowed --name Maarksho-Tiam-Agent --paths panel --hidden-import pyodbc app/tiam-agent/launcher.py
```

خروجی در `dist\Maarksho-Tiam-Agent.exe` ساخته می‌شود. GitHub Actions همین ساخت را روی ویندوز انجام می‌دهد، اجرای پنجره را بررسی و فایل به همراه SHA256 را در Release همان commit منتشر می‌کند.


## نسخهٔ ۱.۰.۱ — تشخیص خطاهای SQL

لاگ SQL اکنون حالت امنیتی دریافتی از پنل و علت دسته‌بندی‌شدهٔ خطا را ثبت می‌کند: مشکل گواهی، TLS، ویژگی نامعتبر اتصال، ورود SQL، درایور، نام ویو، مجوز یا timeout. متن خام خطای درایور و رشتهٔ اتصال چاپ نمی‌شوند. برای به‌روزرسانی، برنامهٔ قبلی را متوقف و ببندید، EXE جدید را اجرا و توکن را دوباره وارد کنید؛ آدرس و شناسهٔ پروژه حفظ می‌شوند.
