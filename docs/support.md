# پشتیبانی

مسیر: `/panel/support.php` (مسیر `/support.php` نیز به همین صفحه وصل است). لینک «پشتیبانی» در پنل مدیریت و پنل کاربران نمایش داده می‌شود. این بخش از گردش قدیمی رسید پرداخت مشتریان مستقل است و تأیید هزینهٔ پیشنهادی را ثبت می‌کند.

## گردش درخواست

- کاربر موضوع، دسته‌بندی، توضیحات و تا سه پیوست تصویر یا لاگ را ثبت می‌کند؛ وضعیت اولیه «در انتظار بررسی» است.
- کاربر فقط درخواست‌های خودش را در پروژه‌های مجاز می‌بیند. «حساب کاربری / پشتیبانی عمومی» برای مشکلات عمومی و کاربران فاقد پروژه در دسترس است. هویت کاربران پورتال پیامکی از شمارهٔ تأییدشدهٔ نشست گرفته می‌شود، نه نام نمایشی قابل تکرار.
- فقط ادمین اصلی/مدیر کل تمام درخواست‌ها را می‌بیند، پاسخ می‌دهد، زمان تخمینی و/یا تاریخ اتمام تهران را تعیین و هزینهٔ رایگان یا مبلغ صحیح به تومان را پیشنهاد می‌کند.
- پیشنهاد مثبت، وضعیت را «در انتظار تأیید هزینه» می‌کند و مهلت دقیق ۴۸ ساعت از زمان ارسال پیشنهاد دارد. ویرایش پیشنهاد پیش از پذیرش یک نسخهٔ جدید با مهلت جدید ایجاد می‌کند. کاربر فقط نسخهٔ فعلی را می‌تواند بپذیرد.
- پذیرش، مبلغ و زمان پذیرش را ثبت می‌کند و درخواست آمادهٔ اجرا می‌شود. ادمین آن را به صف اجرا/در حال اجرا می‌برد و در نهایت پاسخ‌داده‌شده یا حل‌شده می‌کند. هزینهٔ پذیرفته‌شده قابل ویرایش نیست.
- رد پیشنهاد یا رسیدن به لحظهٔ پایان ۴۸ ساعت، درخواست را لغو می‌کند. پس از انقضا، حتی بدون اجرای cron نیز پذیرش یا شروع اجرای درخواست ممکن نیست. علت لغو و سابقهٔ پیام‌ها برای کاربر قابل مشاهده است.
- پیشنهاد صفر، اجرای رایگان است و نیاز به تأیید مبلغ ندارد. تیکت لغوشده یا حل‌شده قابل ویرایش نیست؛ سابقه و پیوست‌های آن باقی می‌ماند.

## نگهداری و زمان‌بندی

جداول `support_tickets` و `support_messages` در نخستین استفاده با همان اتصال MySQL/SQLite برنامه ساخته می‌شوند؛ دادهٔ تیکت‌های قدیمی تغییر نمی‌کند. هر تغییرِ گردش و پیام مربوط به آن در تراکنش ذخیره می‌شود. قفل مشترک فایل، عملیات هم‌زمانِ همین ماژول را در یک هاست هماهنگ می‌کند.

انقضا هنگام مشاهده/عملیات نیز بررسی می‌شود. همچنین به cronهای موجود ریشه و `panel/cron.php` متصل شده است. برای ثبت منظم انقضا حتی وقتی بازدیدی نیست، cron هاست را هر دقیقه یا چند دقیقه روی `php /path/to/app/tools/support-maintenance.php` تنظیم کنید. زمان‌ها به‌صورت Unix epoch ذخیره و به وقت تهران نمایش داده می‌شوند.

پیوست‌ها خارج از document root، به‌طور پیش‌فرض در پوشهٔ خصوصی کنار ریشهٔ سایت، ذخیره می‌شوند. `SUPPORT_STORAGE_DIR` را می‌توان به یک مسیر خصوصی و دائمی خارج از وب تغییر داد. در نصب چندنودی این مسیر باید مشترک باشد. فایل و قفل‌ها باید برای کاربر PHP قابل نوشتن باشند. از دیتابیس و پوشهٔ پیوست‌ها با هم پشتیبان بگیرید؛ حذف یا جابه‌جایی مسیر، پیوست‌ها را از دسترس خارج می‌کند.

افزونهٔ PHP `fileinfo` لازم است. نوع واقعی فایل و پسوند بررسی می‌شود: PNG/JPEG/WebP و TXT/LOG/JSON. هر پیوست حداکثر ۳، مجموع پیوست‌ها حداکثر ۶ و کل درخواست حداکثر ۷ مگابایت است؛ محدودیت پایین‌ترِ خود هاست نیز اعمال می‌شود. دریافت فایل از کنترل‌کنندهٔ دارای بررسی مالکیت انجام می‌شود، نه لینک عمومی. فایل اجرایی، HTML و SVG پذیرفته نمی‌شود.

مجوزهای مشاهده/استفاده و وضعیت جهانی «پشتیبانی» در تنظیمات امکانات قابل کنترل است. مجوز عادی مدیریت پروژه اجازهٔ دیدن یا پاسخ‌گویی به تیکت کاربران دیگر را ایجاد نمی‌کند. تمام POSTها به توکن CSRF نیاز دارند.

آزمون گردش واقعی SQLite و مرز ۴۸ ساعت: `php tests/support_tickets.php`.

### Unicode database compatibility and diagnostics

Support tables explicitly use InnoDB and utf8mb4 on MySQL/MariaDB. Existing tables with a different character set are converted once, preserving rows; back up the database before deployment as with any schema migration. The database user needs CREATE and ALTER permissions. SQLite uses its native Unicode storage. Database failures show a reference code; the PHP error log records that reference, operation, numeric error and SQLSTATE, without ticket contents or connection credentials. A live-host failure still needs its matching log entry to establish its cause.

Run `php tests/support_tickets.php` for SQLite workflow checks. An isolated MySQL/MariaDB server can run `SUPPORT_TEST_MYSQL_PORT=3308 php tests/support_mysql.php`; it creates and drops only a random `support_test_*` database. Optional host/user/password environment variables have the same `SUPPORT_TEST_MYSQL_` prefix. This test reproduces the latin1/Persian failure, verifies conversion, emoji, cost consent, resolution, and repeated initialization.

### Activate the owner on the deployed host

While signed in as an existing main administrator, open `/panel/system-admin-setup.php` (also linked in the main panel header and Support). Enter the desired username, password and password confirmation, then create the account. The account is written to this host's private, Git-ignored administrator file. Sign out and sign in with the new account. Git publishing does not create accounts on a remote host. A non-global or stale session sees only a credential revalidation form. It can proceed only after verifying an actual global account (the private administrator file or `super_users`, never project users). Revalidation rotates the session ID and CSRF token; five failed attempts lock that session for 15 minutes. Account creation requires a verified main administrator session and CSRF token. Passwords are neither prefilled nor sent through Git.
