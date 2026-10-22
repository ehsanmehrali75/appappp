# Project selection and recovery

Baseline inspected: `bea3643`; the initial worktree was clean and `origin/main` matched it. No unpublished earlier work was assumed present.

## Project boundaries

Every login opens `/panel/workspace.php`. The chooser reads project metadata only and displays verified memberships; only system administrators see central management. Project navigation and switching require explicit selection. Direct links to a different project fail closed. Admin and portal share a top project selector.

Rendered POST forms carry `_project_context`. Fetch and XHR requests carry `X-Project-Context`, captured when the page loaded. A changed session selection rejects a stale tab before the controller runs. Project-specific controllers still verify resource ownership and feature permissions. Local GET filters retain the same captured context. The picker itself is a CSRF-protected POST. Automated bearer-token integrations retain their existing project credentials.

## ERP recovery snapshots

The previous ledger stored a whole ERP snapshot in one SQL parameter. On an isolated MariaDB server with `max_allowed_packet=1048576`, that path reproduced SQL error **1153**. The regression stores and reads a roughly 9 MB snapshot with the new bounded 128 KiB chunks and verifies an idempotent retry. This establishes a reproducible failure mechanism; production logs are still needed to attribute an individual production 422 conclusively. Snapshot insert failures log the numeric SQL error and parameter size without logging product data or credentials.

Old inline snapshots remain readable. Large payloads use a checksummed, ordered chunk manifest. Import preparation does not write products. Actual product batches, version updates and receipt acknowledgements retain their SQL transaction. A repeated request uses durable receipts; later manual changes block rollback rather than being overwritten.

## Backup scope

`/panel/backups.php` is available to the selected project's administrator and system administrators. It provides manual snapshots, version listing, download, JSON upload, validation, preview and final restore confirmation. Weekly snapshots run from the existing `cron.php` worker.

Legacy JSON exports can be uploaded to the previewed recovery page; users, settings and schedules are discarded, and old media references require the original host files. The old unpreviewed restore action is disabled, including for owners.

Snapshots include the **product library**: product metadata, stock, variants, descriptions, review information and referenced local media in `panel/uploads` or root `uploads` with allowed image, PDF or video extensions. Local media are embedded, checksummed and restored under a new project-specific path; files shared with other projects are not overwritten. Restored versions keep existing file references intact in other projects.

Not included/restored: remote image bytes, unreferenced files, application source, WordPress server/database itself, data-hub/SEO/VM private stores, credentials, users and permissions, subscription/billing/payment records, project settings, schedules and external publication queues. This is a product-library recovery facility, **not a whole-server disaster recovery backup**. Maintain independent host/database backups for those excluded resources. JSON uploads are limited to 32 MiB; a large media-heavy snapshot can be restored from its server-side saved version without re-uploading it.

Before any restore, a fresh safety snapshot is saved. Invalid checksums, a different project ID and product IDs owned by another project are rejected. Product writes use one SQL transaction; newly copied media are removed if it fails. Existing users, billing and unrelated projects remain intact. In-progress ERP import jobs from before recovery are invalidated by a recovery revision. Automatic ERP/WordPress integration is disabled, automatic processing and existing scheduled publication are held until explicit review and resumption. The recovery page has a separate confirmation to resume existing queues; integration switches must be re-enabled separately.

Backups default to `panel/db/project-backups`, denied by the existing web-server private-directory rules. Set `MAARKSHO_BACKUP_DIR` to a persistent private directory outside the public document root where possible. Backups contain business data and must stay private. Files have mode 0600 and project directories mode 0700. No automatic retention/deletion is applied: provision space and manage retention independently.

## Deployment and Cron

- Deploy the code to the existing PHP host without replacing `.env`, `panel/db` or uploads. PHP 8.2+ with mysqli or sqlite3, mbstring and fileinfo is expected; The installable WordPress ZIP includes its template, stylesheet and script and does not require the PHP ZIP extension.
- The SQL account needs CREATE TABLE for `inventory_version_chunks`; existing ledger rows do not need rewriting. Product and ledger tables must use a transactional engine on MySQL (InnoDB).
- Configure the host scheduler to run `php /absolute/path/to/cron.php` every five minutes. Each project's weekly task is due seven days after its last successful snapshot, with a per-project nonblocking lock. The backup page displays the last weekly success and latest error. A lazy browser visit is not a substitute for real Cron.
- The PHP worker needs write access to the backup directory and uploads; size memory/disk for embedded media. To upload downloaded backups up to 32 MiB, set `upload_max_filesize=32M` and `post_max_size` above 32 MiB.
- Reinstall the updated plugin from the WordPress connection page, save the selected project's site URL, reveal/copy its API token into the plugin, save it, then use the panel's connection test and paginated receive workflow. Enable writing only when sending reviewed changes is intended.
- WordPress tests in this repository use explicit API/WordPress doubles. No real customer WordPress site or production ERP was contacted or modified. Run the connection test against the intended site after deployment before using live imports/publication.

## Regression commands

```sh
python -m unittest discover -s tests -p 'test_*.py' -v
python tests/browser_project_recovery.py
python tests/browser_wordpress_workspace.py
# Optional isolated MySQL/MariaDB server started with max_allowed_packet=1M:
MAARKSHO_TEST_MYSQL_SOCKET=/path/to/test.sock php tests/mysql_inventory_snapshot.php
```

All destructive regression operations use disposable databases and copied application directories.

## Verified in the implementation environment

- The complete Python/real HTTP regression suite passed: 281 tests. Follow-up acceptance checks cover explicit login selection, missing-asset fallback, central management, stale tabs, corrupt/foreign backups, upload preview, failed restore rollback, weekly status/error handling and invalidation of pre-recovery import jobs.
- Real MariaDB: the legacy packet error 1153 was reproduced at a 1 MiB packet limit; a 9,180,293-byte snapshot round-tripped with bounded chunks and one ledger row after retry.
- Chromium: two-tab isolation, native forms and AJAX, 1,000-row import/confirmation/resume/rollback, WordPress fixture receive/preview/send/acknowledgement, settings and mobile layouts were exercised.
- Standalone PHP contract/security checks and PHP/JavaScript syntax checks passed.

Live WordPress/ERP connection verification and host Cron installation remain deployment checks; they were not simulated as successful production operations.

## Workspace and connection UI follow-up

The admin/portal context bar links directly to **اتصال وردپرس و کلید API**. In its connection tab, **ساخت کلید API** creates the project key without requiring a site URL, enabling synchronization or changing the platform. Repeated creation preserves the existing key. Only users with the connection use permission can reveal/copy it. The same page documents plugin installation, key entry, HTTPS URL, actual connection testing and paginated receive.

Plugin 6.1.0 ships a responsive Persian RTL settings console. A saved key is labeled as saved, not as a successful connection; actual connection verification remains in the panel. Install the complete ZIP rather than copying only the main PHP file. Workspace selection, the shared context bar and project backup pages share a responsive visual design. Backup restore still requires a fresh preview and captures a safety version before replacement.

### Follow-up validation

- Full Python/HTTP discovery executed 290 tests: 289 passed; one chooser markup compatibility assertion failed. Restoring the existing `project-setup-form` marker fixed it, and the affected purchase/login regression passed on rerun. The additional WordPress suite then passed all 16 tests, including independent stable token creation, view-only token protection and complete ZIP contents.
- Chromium passed two-tab isolation, 1,000-row ERP import/resume/rollback, WordPress fixture read/preview/send/acknowledgement, portal/admin workspace checks and desktop/390/320-pixel layouts for chooser, connection, backup and plugin settings. No JavaScript errors or horizontal overflow were observed in those checks.
- PHP syntax, JavaScript syntax, 44 request security checks, 15 ERP contract checks, 82 catalog checks and the code-only release archive validation passed.
