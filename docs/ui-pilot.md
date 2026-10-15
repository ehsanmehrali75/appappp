# Dashboard pilot and verification

The pilot is the first acceptance stage from the supplied redesign prompt. It changes only the authenticated project dashboard. Support and owner activation share its visual tokens. Other editors, iframe documents, public catalogs and module screens retain their existing UI until appearance/UX acceptance. PWA and new notification delivery are not implemented in this stage.

## Use and compare

Select a project, then open `/panel/admin.php?tab=dashboard`. The new shell is enabled by default. `/panel/admin.php?tab=dashboard&design=legacy` gives the previous layout for comparison. Existing project switching still redirects to Products as before; switch first, then open Dashboard.

The new shell keeps server-generated permission checks and links, adds collapsible navigation categories and desktop sidebar, and corrects the mobile-menu breakpoint. Mobile drawer focus is contained, Escape closes it, and focus returns to the trigger. Existing sidebar search stays connected to the same search handler. Header actions have accessible labels and local line icons. No frontend framework or build dependency was added.

Cards still use project products, votes, schedules and pending-review counts. Chart display now ignores missing extraction dates and matching months from earlier years in pilot mode; it does not edit saved products. Empty popular-content and calendar states reflect actual data. The legacy comparison keeps its previous chart behavior. Canvas has a data-derived accessible label.

## Validation evidence

Tests ran against a temporary local copy using synthetic fixture accounts/data, Chromium and PHP 8.4. External image assets were replaced with a small fixture image to avoid external image retry loops; these screenshots are a design sample, not production data. No live-host validation is claimed.

* Browser: 320, 375, 768, 1024 and 1440px without document overflow; visible menu trigger at 1024px; drawer focus entry, Tab/Shift+Tab containment and Escape restoration; actual touch opening/overlay dismissal; desktop sidebar collapse and category/search interaction.
* Legacy comparison and Settings did not load the pilot stylesheet. No browser page errors were recorded during the dashboard checks.
* Support HTTP: creation, private log/image uploads, cross-user denial, CSRF, pricing/acceptance, queueing, response and resolution.
* Owner activation: authenticated creation and subsequent login; unauthorized sessions can only revalidate a real global account, not create owners with project credentials. CSRF is checked and rotates after successful revalidation.
* PHP: 27 support workflow checks, 24 access-control checks, account-storage/authentication checks, plus syntax validation. MariaDB 11.4 reproduced latin1 errno 1366 then passed utf8mb4/InnoDB migration, Persian/emoji round trips, quote consent, resolution and idempotent initialization.

### Indicative local comparison

Single browser navigations in the same fixture environment (legacy layout vs pilot), not a benchmark or production speed claim. Existing large PHP document and Tailwind runtime remain; broader asset optimization is a separate change.

| Measurement | Legacy | Pilot |
|---|---:|---:|
| Load event end | 811 ms | 773 ms |
| DOMContentLoaded end | 689 ms | 652 ms |
| Compressed HTML | 68894 B | 69406 B |

Browser JSON and before/after screenshots are retained under `/workspace/artifacts/ui-pilot/` in this task environment. This is not a full WCAG audit, cross-browser/iOS validation, load test or complete module regression. New controls have visible focus, minimum touch sizes and contrasting foregrounds; older module badges/components need their own accessibility checks in later phases.

## Deployment and next acceptance

Deploy all changed PHP, CSS and JS files together; GitHub publication does not establish live deployment or create a remote owner account. Support table upgrades require CREATE/ALTER privileges and a normal database backup. Existing private account files and attachments must be preserved. After deployment, verify the owner-activation page and submit a real support ticket. If a database error remains, use the displayed reference code to locate the numeric error/SQLSTATE in the PHP server log.

Review the desktop/mobile dashboard appearance first. Per the attached prompt, module migration, PWA and real-event Web Push follow that acceptance and their independent backend/configuration checks.
