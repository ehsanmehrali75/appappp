# Maarksho Flow v3.0 - PHP Backend Edition

This is the consolidated, final PHP version of the Maarksho Flow application. It is designed for production environments using Apache/Nginx, PHP 7.4+, and MySQL.

## 🚀 Key Features
- **Smart Scraper**: Automated product extraction from Tanedorost.com.
- **Project Dashboard**: Multi-project management with detailed statistics.
- **Social Media Hub**: Instant and scheduled posting to Telegram, Bale, and Rubika.
- **Premium Portal**: High-end product showcase for user voting and feedback.
- **Excel/CSV Bridge**: Bulk import products and automate calendar scheduling.
- **Hybrid Storage**: Priorities MySQL performance with JSON fallback resilience.

## 📁 Installation Guide
1.  **Server Requirements**: PHP 7.4+ (with `mbstring`, `curl`, `mysqli`, `dom`) and MySQL 5.7+.
2.  **Database Setup**: 
    - Create a database named `maarksho_flow`.
    - Import the `magtaned_flow.sql` file provided in this directory.
3.  **Configuration**:
    - Open `inc/db.php`.
    - Update `$db_config` with your host, username, password, and database name.
4.  **Permissions**:
    - Ensure the `/uploads` directory is writable by the web server (`chmod 775`).
    - Ensure `db/projects_v2.json` is writable if using JSON fallback.
5.  **Access**:
    - Navigate to `index.php` in your browser.
    - Default Credentials: `admin` / `1375`.

## 🛠 Advanced Usage
- **Scheduling**: To enable automated scheduling, set up a Cron Job to point to a trigger script (can be implemented in `functions.php`).
- **Telegram Bot**: Obtain a Bot Token from @BotFather and set it in the Project Settings within the Admin Panel.

---
*Developed with precision by the Maarksho Flow Engineering Team.*
