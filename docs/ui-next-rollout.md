# Remaining administrative pages and independent workspaces

This rollout extends the approved gray/sage design beyond the initial dashboard and four workspace tabs. It does not claim the visual editors or all independent applications have been redesigned.

## Scope

Shared workspace styles now cover products, CSV import, website export, occasions, advertising plans, auto-admin and its logs, personnel performance, activity logs, project administration, plans/orders/site settings, storage/debug/feature flags, and the Mark Show administration tabs. Landing builder receives navigation-shell styling only; its editor and generated documents retain their own styling.

Prompt Hub and task management receive a separate scoped stylesheet and progressive enhancements, in both root and `/panel/` copies. Their PHP actions, data formats, permission checks, and destinations remain intact. `design=legacy` omits the new styling and enhancements on each route.

The library workflow report starts collapsed on mobile; users can expand it explicitly. Product and prompt cards support Enter/Space. Ordinary form dialogs have labels, initial focus, Tab containment, Escape dismissal and return focus. Visual product preview/canvas interactions are excluded from generic dialog handling.

Mobile task boards expose status selection with counts read from the existing badges. All columns remain available on desktop and without JavaScript. Mobile filters and statistics can be expanded independently, keeping tasks above the fold. Prompt filters are keyboard accessible at the existing 1100px drawer breakpoint; modal bodies scroll with save controls available.

## Import repair discovered through functional verification

The upload form previously submitted `excel`/`upload_excel`, while its PHP handler expects `excel_file`/`import_excel`. The corrected form reaches the existing mapping step. Drag/drop uses the native file input and the same POST path. Invalid extensions produce inline feedback and never submit. When the optional SimpleXLSX library is absent, the file picker offers CSV rather than promising unsupported Excel parsing.

The handler also passed Windows-1256/CP1256 names to mb_detect_encoding, causing a PHP ValueError on the tested PHP build. UTF-8 now remains intact; legacy Windows-1256 conversion uses iconv when available, with an explicit CSV UTF-8 recovery message otherwise. CSV parsing uses an explicit escape argument. Imported column labels/values are escaped in mapping options.

## Evidence and limits

- Chromium checked 22 additional admin tabs at 1440/375/320px: no document overflow or page errors. This is route/render coverage, not full mutation coverage of every administrative feature.
- Both Prompt Hub and task routes, including root aliases/copies, checked at 1440/1024/768/375/320px: no document overflow or page errors.
- Synthetic CSV was dropped through the browser, mapped and saved; the resulting product was found by search. Invalid file rejection, workflow disclosure, keyboard product opening and edit-dialog dismissal were exercised.
- Touch checks passed for mobile task status/filter selection and prompt form save-control visibility. Imported HTML-like header names remained plain option text; unsupported legacy encoding produced the UTF-8 recovery message.
- Prompt and task creation succeeded through their real HTTP handlers on desktop and 375px mobile, with persisted data visible after reload. Task tests used no assignees, so no external notifications were requested.
- PHP syntax and JS syntax checks pass. Existing suites: access 24, ERP contract 15, Tiam inventory 39, support workflow 27.
- Artifacts: `/workspace/artifacts/ui-next`, including viewport reports, screenshots and `flows.json`. External browser assets were fulfilled with inert test responses to isolate local UI checks. No live hosting, SQL connection or Push delivery is implied.

Independent size/visual studios, catalog, portal, chat, specialist SEO/sales/VM interfaces, PWA and event-notification delivery remain separate work. Do not apply these broad administrative form rules to canvases, iframe templates or generated images.
