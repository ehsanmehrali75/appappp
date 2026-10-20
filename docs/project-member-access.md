# Project member access

Create/edit a member in **Users** and select:

1. One or more projects (only a global administrator can assign multiple projects).
2. Feature permissions for the member, using the existing view/use policies.
3. **پورتال**, **پنل ادمین**, or both entry points. At least one is required.

Entry permission never grants a global administrator role or extra feature permission. A project administrator retains the existing authority within their project. Use the manager/editor/viewer roles for accounts that need a selected set of features; viewers retain read-only restrictions unless an explicit use policy allows an operation.

The nullable `project_users.panel_access` JSON column is added automatically by the normal schema migration. Explicit selections persist through membership edits, ordinary project saves and password login/OTP sessions. Existing members with NULL retain their former entry behavior: project administrators/managers/editors can use both pages, viewers use the portal. Global administrators retain both entry points.

Each request refreshes the persisted member before checking the entry point. Direct page requests and POST requests are checked on the server. Project switching first verifies membership in the requested project and then applies that project's entry permissions. Standalone/shared module endpoints continue to enforce their feature and operation policies.

Membership saves also upgrade inherited MySQL schemas when required: role ENUM/short VARCHAR becomes VARCHAR(50), short password columns become VARCHAR(255), short permission columns become TEXT, and the member table uses InnoDB/utf8mb4. Existing members are preserved. MySQL updates use an upsert that retains foreign-key references instead of deleting and replacing the member row. Only projects involved in the affected account are written.

Failures return a tracking ID. The matching server log includes the operation stage, database error number and SQLSTATE without SQL text, entered fields or credentials. Run `tests/project_members_mysql.php` against an isolated server using `MEMBERS_TEST_MYSQL_PORT` and `MEMBERS_TEST_MYSQL_PASSWORD` to reproduce and verify the legacy-schema cases.
