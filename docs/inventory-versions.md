# Inventory preview and rollback

Tiam settings now provides **دریافت کامل تیام و بررسی قبل از ثبت**. Save the mapping first, then request the full snapshot. The existing agent fetches it; no inventory is changed until the user confirms the preview. Cancel discards the preview. Scheduled sync retains its existing explicit approval policy.

Manual ERP JSON/Excel import also stops at a review dialog before its first write. Review includes new/updated/zeroed counts, paginated products, stock before/after, and SKU/size details. Confirm continues the resumable batch import; cancel leaves products unchanged.

Both screens show SQL-backed import history with the same change list and **بازگشت به قبل از این واردسازی**. Each batch's product writes and rollback journal commit in the same SQL transaction. History survives removal of temporary uploaded files. Latest 30 runs are shown; journal data is retained in `inventory_import_versions` and should be included in database backups. Credentials are not part of this ledger.

Rollback restores only fields changed by that import. Later edits to other fields, such as a photo, survive. A later edit to an affected field stops the entire rollback with a conflict message; nothing is partially undone. Newly created products are removed only if they are still unchanged; original image files are never deleted. A partially applied import can also be undone, and its remaining batches cannot resume afterward.

Rollback pauses configured Tiam automatic synchronization and clears its previous approval, preventing the next scheduled run from immediately reapplying the snapshot. Projects with Tiam configuration require settings permission for this operation. Verify the mapping and obtain/approve a new preview before re-enabling automatic synchronization. Active Tiam jobs must be cancelled before rollback.

All endpoints require an authenticated project member, product-use permission, CSRF protection, and source permission (ERP: extraction-use; Tiam: settings-use). Snapshots are bound to their project. This feature requires the SQL backend; no live inventory is executed during deployment.
