# Project member access

Create/edit a member in **Users** and select:

1. One or more projects (only a global administrator can assign multiple projects).
2. Feature permissions for the member, using the existing view/use policies.
3. **پورتال**, **پنل ادمین**, or both entry points. At least one is required.

Entry permission never grants a global administrator role or extra feature permission. A project administrator retains the existing authority within their project. Use the manager/editor/viewer roles for accounts that need a selected set of features; viewers retain read-only restrictions unless an explicit use policy allows an operation.

The nullable `project_users.panel_access` JSON column is added automatically by the normal schema migration. Explicit selections persist through membership edits, ordinary project saves and password login/OTP sessions. Existing members with NULL retain their former entry behavior: project administrators/managers/editors can use both pages, viewers use the portal. Global administrators retain both entry points.

Each request refreshes the persisted member before checking the entry point. Direct page requests and POST requests are checked on the server. Project switching first verifies membership in the requested project and then applies that project's entry permissions. Standalone/shared module endpoints continue to enforce their feature and operation policies.
