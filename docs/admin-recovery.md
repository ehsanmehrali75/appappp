# First owner recovery on shared hosting

An account named `admin` can belong to a project or hold a stale session; its name alone is not proof of global authority. Global password revalidation remains available. When there is no account with role `owner` in the deployment's private administrator file, the setup page also offers first-owner recovery using access to the host's File Manager. No old global administrator password is required for that path.

1. Deploy this version and open `/panel/system-admin-setup.php` while signed in with an existing panel account.
2. In **ساخت اولین مدیر کل با دسترسی هاست**, download the temporary key file.
3. Use the host's File Manager to put that exact file at `panel/db/owner-recovery-key.php`, relative to the website root. Keep the `.php` extension; do not add `.txt`. Alternatively copy the PHP source shown under the manual-file instructions.
4. Return to the same page without refreshing; keep the generated token in the form, enter the owner username/password and password confirmation, and submit. The file is valid for one hour. If the page was refreshed, reupload the newly generated file or enter the token associated with the previous file.
5. Success creates an unrestricted owner and rotates the authenticated session to it. The key file is deleted and initial recovery is disabled because an owner now exists. Open the panel.

The file contains only a SHA-256 token hash and expiry, with a direct-access PHP guard. The account password is never embedded in that file or committed to Git. Both the temporary key file and private account file are ignored by Git. Downloading a key alone grants no access: the owner must place its matching hash on the host. All submissions require an existing authenticated panel session and CSRF token. A missing, mismatched or expired key cannot create an owner. Owner absence is checked again inside the account-file write lock, preventing replay or concurrent duplicate bootstraps. Existing accounts are preserved; recovery never overwrites them.

This is an initial activation flow, not a public reset endpoint for existing owners. If an owner already exists, use that account or manage it directly with authorized host access. Do not leave an unused recovery file on the host; delete it if activation is abandoned. The application needs write access to its private `panel/db` directory to save accounts.

Validation: `php tests/owner_recovery.php` checks missing/mismatched/expired keys, hash-only source, validation failure preservation, successful owner authentication, key deletion and replay prevention. Existing administrator and access-control tests continue to pass. A real Chromium HTTP test in an isolated ownerless fixture also exercised download, host file placement, CSRF rejection, account creation/login and recovery closure at a 375px viewport. These checks do not establish activation on the production host.
