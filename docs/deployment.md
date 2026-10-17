# Deployment and updates

Use PHP 8.2+ and the extensions listed in README. Point Apache at the repository root and keep both `.htaccess` files. For Nginx, explicitly deny `.env*`, `.git`, all `db`, `inc`, `tmp`, `tests`, and `tools` directories, SQL/SQLite/log/cache files and executable/HTML/SVG files inside uploads. PHP's development router enforces equivalent private-path rules. Public diagnostics and emergency-reset scripts now return 404; protected log viewers require a main administrator.

## Preserve the host's data

Back up the live database, private configuration, `panel/db` and both uploads directories before deployment. Use a code-only package:

```sh
python tools/build_release.py --output /tmp/maarksho-release.zip
```

It excludes credentials, runtime databases, uploaded images, logs, SQL dumps, tests and tooling. `release.json` records the source commit, whether the source was dirty, and SHA-256 hashes. Extract onto the host while preserving existing files; never use a command that deletes files absent from this ZIP. Create writable `panel/db`, `panel/uploads` and `panel/scraper` directories on new installations. Grant only the permissions the hosting provider requires, rather than making them world-writable.

Some runtime files and a `.env` were already tracked in earlier repository history. This release deliberately does not delete those tracked files because a Git-based update could otherwise remove live data. A raw checkout/overwrite is therefore not a safe deployment package. Existing database passwords exposed in the repository must be rotated through the host's database administration and updated in its private configuration. Removing a value from current PHP source or `.env.example` does not remove it from Git history. No database password has been rotated automatically by this change.

## Database configuration

Set `MAARKSHO_DB_DRIVER=mysql`, `MAARKSHO_DB_HOST`, `MAARKSHO_DB_NAME`, `MAARKSHO_DB_USER`, and `MAARKSHO_DB_PASS` in the host environment or a private root `.env`; never deploy the example as working credentials. Existing `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` are supported for compatibility. Environment values override `.env`. A host-local `panel/db/database.local.php` returning an array can explicitly override all of these; it must stay private and out of Git.

Both root and panel share one connector. A failed MySQL connection returns an error instead of writing into SQLite or JSON. Leave `MAARKSHO_DB_ALLOW_FALLBACK=0`. To intentionally use SQLite, set `MAARKSHO_DB_DRIVER=sqlite` and `MAARKSHO_SQLITE_PATH` to an absolute writable private path. JSON is an explicit compatibility mode, not an automatic failover. Check the existing MySQL connection and private `.env` before deploying; missing credentials must be corrected on the host.

## Accounts and browser requests

There is no new known-password account. Existing users remain intact; successful legacy plaintext logins upgrade their stored password to a hash. New passwords are hashed. Use the [first-owner recovery instructions](admin-recovery.md) when an existing global administrator is unavailable. Never commit the private owner/account or recovery-key files.

Browser mutations require session CSRF protection; reload open panels after updating so their forms and requests receive the new token. Agent/webhook interfaces retain their own authentication. Sensitive backups are restricted to main administrators. Original uploaded final photos are preserved, MIME-checked, and sent through approval; importing already-published website photos does not add a second approval step.

## Scheduled worker

Configure the hosting provider's cron to run the CLI worker, for example:

```sh
cd /absolute/site/root/panel && /usr/bin/php cron.php
```

Keep the existing schedule appropriate for the deployment (normally every 1–5 minutes). The worker handles global scheduled tasks and can publish configured content. Public GET triggers are disabled. A browser trigger must use POST with a valid main-administrator session and CSRF token; project managers cannot run the global worker. Tiam's workstation agent continues using its separate HTTPS token-authenticated endpoint and the workstation's current LAN/VPN connection.

The panel's extraction/global timers share a single dispatcher, throttle successful requests to one per minute, and back off after network errors. Both CLI entry points and browser triggers share one nonblocking worker lock. On PHP-FPM, a background trigger returns an `accepted` acknowledgement before executing jobs with `fastcgi_finish_request()`, after releasing the session lock. An already-running worker returns `busy` immediately. The acknowledgement confirms dispatch, not successful completion of every job; inspect worker logs for outcomes.

On hosts without `fastcgi_finish_request`, automatic browser dispatch returns `cron_required` immediately and does not start a long synchronous request. Configure the CLI cron above instead. Optional `MAARKSHO_WORKER_STATE_DIR` selects a private persistent writable directory for the lock and last-run timestamps; the default is a deployment-specific directory under the system temporary directory. A network timeout means dispatch acknowledgement was unavailable, not proof the worker stopped. This is separate from Tiam agent polling.

Browser-dispatched runs take one scraping job and one scheduled post per query by default; CLI runs take up to five. `MAARKSHO_WORKER_BATCH_SIZE` can set 1–20. A run stops taking additional jobs/posts after its wall-time budget (45 seconds for browser dispatch, 240 for CLI; override `MAARKSHO_WORKER_BUDGET_SECONDS`, bounded to 5–600). The budget is checked between items; it does not interrupt an in-flight download or guarantee that a single large import fits the host's execution/memory limits. Remaining items stay queued for a later run.

## Verify after deployment

Sign out/in and reload the panel. Check project switching, a harmless product edit, a small reference upload, approval, library/archive filters and Size Studio. In project settings, configure request/reference prerequisites and their authorized operators. On a project with both prerequisites enabled, a photo-less in-stock item moves from request to reference to production; with an optional reference, it appears in both the missing-reference and production queues. Stock-zero products stay in the archive.

Check the live site's database connection and a host-owned backup restore separately. Local synthetic tests and a GitHub push do not prove that the production host has deployed the new commit or that its database credentials and private files are correct.


## Large central ERP imports

The central inventory form and ordinary ERP JSON upload now send at most 100 rows / about 450 KB per JSON request. The server stages the **complete** snapshot, normalizes warehouse/size/color totals once, then saves batches of 50 final products. Missing-stock decisions are made from the complete snapshot, never from an individual upload chunk. Existing original media remains in the merged products. Privileged full-backup restore remains a separate path.

Imports require SQL, verified membership, both `extract` and `products` use grants, and a CSRF token. They release the PHP session lock before processing. Private state defaults to a deployment-specific directory under the system temporary directory; use `MAARKSHO_IMPORT_STATE_DIR` for a persistent writable directory **outside the web root**. Jobs expire after 24 hours; each new import removes at most one expired directory. Limits: 50,000 rows / 32 MB, with host memory and execution limits still applying to the single normalization step. Larger snapshots need a dedicated background import service.

The `product_import_receipts` table is created automatically (database user needs CREATE TABLE). Each receipt commits in the same transaction as its product batch. A lost HTTP response or state-file acknowledgement is reconciled from SQL. Press import again with the same data/mode in the same browser session to resume; after a reload, reselect the same file. Progress is retained in session storage without storing the source data there. Sign-out or an expired session requires a new import. Already committed batches remain applied if an import is interrupted; this is not an all-or-nothing transaction for the entire file. Concurrent product edits stop the affected batch rather than silently replacing the changed record. Do not delete private job state during an active import.
