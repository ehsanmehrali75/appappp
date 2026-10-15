# Portal navigation and image-production stages

The portal now uses the same sage/gray tokens, right sidebar, project context and quick-access hierarchy as administration. The top bar contains account and task controls; production/archive and project tools are grouped in the sidebar. Mobile uses a searchable drawer with Escape dismissal and focus return. Inventory reports, secondary filters and bulk actions are collapsible. Normal links preserve browser history and prevent stale navigation after switching tabs.

The library retains every product, including zero-stock originals, and can filter by the shared workflow states. Pending approvals distinguish website-source approval evidence from new manual images. Rejecting a photo preserves the product and originals. Total-inventory thresholds use the same strict greater-than logic as reconciliation; unknown inventory is distinct from zero.

## Per-project production settings

In project settings, **مراحل تولید تصویر این پروژه** has independent checkboxes:

| Request required | Reference required | Photo-less in-stock product |
| --- | --- | --- |
| On | On | Request → missing reference → production |
| On | Off | Request → missing-reference and production queues concurrently |
| Off | On | Missing reference → production |
| Off | Off | Missing-reference and production queues concurrently |

Only real reference assets complete reference work; a stale `has_reference` flag cannot do so. An existing reference is retained when requirements change. If requests are required, a reference does not impersonate an explicit request. The request can be registered from the reconciliation row, and the next stage is derived immediately. Its actor/time are recorded. Authorized reference operators can upload through the existing reference modal; completing that upload refreshes the queues. The designated production-request and reference-user settings remain independent. Main and project administrators retain their administrative access.

Both extraction and Website Export's work view offer request, missing-reference, reference-ready and production filters with counts. Optional reference work overlaps production intentionally. A final non-social photo leaves these prerequisite queues and uses the existing approval/publication workflow. Known zero inventory leaves execution queues and remains in the library/archive with its files intact.

## Validation

- 42 pure production checks cover all checkbox combinations, project isolation, real versus stale references, final/social assets, stock-zero exclusion and request operators.
- Real HTTP tests cover stage transitions, actor persistence, unauthorized operators, prerequisite bypass, approvals, stock thresholds, pagination, private files, backups, CSRF, uploads, canonical routes and explicit database failure.
- `tests/browser_workspace.py` exercises request → actual reference-file upload → production, real settings persistence, mobile drawer/search/Escape/account access, and five portal views at 1440/768/375/320px. The recorded run passed 59 checks with no JavaScript errors or horizontal document overflow. It uses a disposable SQLite fixture and stubs remote assets/workers; it does not verify the live hosting environment or real external integrations.

## Large-catalog blank-page correction

The shared HTML/CSRF decorator previously matched entire script blocks with a lazy whole-document regular expression. A product JSON block larger than PHP's PCRE backtracking limit made that replacement return null, leading to a successful HTTP response with an empty body. A small fixture did not expose this failure.

The decorator now scans short opening tags, skips script/style/template/text blocks by their closing boundaries, and inserts protection only into real local POST forms. It preserves multi-megabyte scripts exactly and never replaces the document with a regex failure. Legacy serialized website metadata lists are normalized before workflow calculations, without creating approval or upload evidence from malformed values.

Regression coverage includes a 5 MB inline script under a deliberately low PCRE limit, a real compressed portal response with 900 additional products, both the bare portal URL and the library, and Chromium rendering with the large catalog at desktop/mobile sizes. The full browser run passes 59 checks with no page errors. Live-host deployment remains a separate verification.
