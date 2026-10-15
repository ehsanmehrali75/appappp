# Administrative workspace rollout

The approved dashboard language now covers the shared navigation and four administrative tabs: settings, users, extraction and calendar. `neo-app-shell.css` owns navigation only; `neo-workspace.css` is loaded only for those four tabs. Dashboard table-to-card rules remain isolated to the dashboard. Public views, image editors, size-chart exports and independent applications are outside this rollout.

Forms use inset surfaces and visible keyboard focus, controls have 44px minimum height, existing labels receive field associations, and settings has a compact horizontally scrollable section navigation. Tables preserve native semantics and scroll locally rather than changing calendar geometry. Existing form names, actions and permissions remain intact. `design=legacy` disables the rollout per page.

Validation uses an isolated synthetic project, Chromium at 1440, 375 and 320 pixels, navigation drawer open/Escape, member form opening, settings section navigation, and legacy fallback. ERP preview/download/manual parser flow was exercised with a simulated agent response; no live SQL connection or production host deployment is implied. PHP syntax, 24 access checks and 15 ERP contract checks pass. Browser screenshots and viewport results are in `/workspace/artifacts/ui-workspaces`.

PWA and event notifications remain separate implementation stages.
