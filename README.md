# Maarksho Flow

PHP panel for project catalogs, image production and approval, Size Studio, website reconciliation/export, Tiam inventory, content planning and support.

## Runtime

Use PHP 8.2 or newer with `mysqli`, `sqlite3`, `pdo_sqlite`, `mbstring`, `curl`, `dom`, `fileinfo`, `gd` (JPEG/PNG/WebP), `zip`, and `exif`. MySQL/MariaDB is the normal deployment database. SQLite and JSON must be selected explicitly; a failed MySQL connection no longer silently selects another database.

Read [deployment instructions](docs/deployment.md) before updating a live host. Configure credentials through environment variables or a private `.env` file using [.env.example](.env.example). Both root and panel use `panel/inc/DatabaseConfig.php`; never edit database credentials into PHP source.

There is no new default administrator. Existing accounts continue working; legacy passwords are hashed on successful login. Create an unrestricted owner through `panel/system-admin-setup.php` using an existing global administrator or the documented one-time host recovery flow. See [owner recovery](docs/admin-recovery.md); the setup page itself supplies recovery instructions.

## Local checks

```sh
php -S 127.0.0.1:3000 router.php
```

Run regression checks in a separate shell:

```sh
for suite in access_control system_admins owner_recovery support_tickets erp_inventory_contract tiam_inventory tiam_factinventory website_catalog_sync website_workflow website_export image_production request_security; do
  php "tests/$suite.php" || break
done
PHP_BINARY=php python -m unittest discover -s tests -p 'test_*.py' -v
```

These suites use temporary files and synthetic databases. `tests/support_mysql.php` is separate and only runs against an explicitly configured isolated MySQL test server. GitHub Actions repeats the main checks on PHP 8.2 and 8.4.

## Production workflow

Each project can independently require an image-production request and a reference image. Work queues distinguish requests, missing references, references ready for production, final-photo approval, publication, descriptions and size charts. Optional references appear alongside the production queue without blocking it. Known zero inventory stays in the archive and leaves execution queues. Website photos are already approved; manually uploaded final photos retain the approval step. Original final-image files remain available in the library.

Optional browser interaction checks (Chromium + Playwright):

```sh
PHP_BINARY=php CHROMIUM_PATH=/usr/bin/chromium python tests/browser_workspace.py
```
