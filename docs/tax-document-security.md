# Private tax documents

Tax documents use the dedicated `user_tax_documents` table and the private `tax_documents` disk at `storage/app/private/tax-documents`. This disk must stay outside the web root, have no public symlink or URL, and be readable only by the application and authorized backup operators. The existing `public` disk remains for ordinary public media.

On the production Unix host, provision the tax root as `maverick:www-data` with mode `02770`. Files use `0660`; owner directories use `02770`. The setgid bit preserves the shared `www-data` group when either the PHP web service or the authorized CLI operator creates a document. Other Unix users have no access through this root, and application access still requires the controller policy. Membership in `www-data` is therefore restricted to trusted service/operator accounts.

As `maverick`, who is a member of `www-data`, an initially absent root can be provisioned without sudo:

The prepared deployment wrapper runs `scripts/deploy/provision-tax-document-storage.sh` during maintenance, before reopening the application. It validates an existing root without recursively changing files. This prevents the previous code from creating owner-only subdirectories between provisioning and the corrected release.

```sh
install -d -m 2770 -g www-data /var/www/backend/storage/app/private/tax-documents
stat -c '%U:%G %a' /var/www/backend/storage/app/private/tax-documents
```

The expected result is `maverick:www-data 2770`. Inspect an existing directory's ownership before running a corrective command; do not recursively change permissions or ownership on existing documents. If a directory belongs to another Unix owner and needs correction, that owner or a server administrator must perform the reviewed correction. No nginx configuration change is needed for these filesystem modes, and the nginx installer remains a separate administrator operation.

The private disk explicitly maps private files to `0660` and directories to `02770`. The upload and migration service applies directory visibility immediately after creating each owner directory, because `mkdir` alone obeys the process umask and can otherwise remove group write. It does not change the process-wide umask. Existing directories must already have the expected mode and root group; the service checks them and fails safely instead of attempting to chmod a directory owned by the other process. The tax root must be provisioned before either upload or migration. An accidental request for public visibility on this disk maps to owner-only modes, never world-readable modes.

An active owner may upload, inspect their submission summary, and download their own document. An active user whose **primary** role is `admin` or `superadmin` may inspect and download another user's document. Sales, clients, editors, other photographers, secondary administrator roles, locked/inactive users, and impersonation sessions receive no cross-user access. Uploads always target the authenticated user; an administrator cannot submit on somebody else's behalf through this endpoint.

The current upload contract is multipart `POST /api/profile/tax-document`, with `document` and optional `notes` (up to 1,000 characters). New documents must be PDF, PNG or JPG, at most 10 MiB. Server-side file-content detection determines the stored extension; a filename or browser MIME declaration does not establish the type. New files receive a random name under the owner's directory. Notes are stored encrypted in the dedicated table and are excluded from responses and logs.

The dedicated endpoints, all behind authentication, are:

| Endpoint | Response |
| --- | --- |
| `GET /api/profile/tax-document` | Owner's submission summary, or `tax_document: null` |
| `GET /api/profile/tax-document/download` | Owner's authenticated attachment download |
| `GET /api/admin/users/{user}/tax-document` | Authorized owner/administrator summary |
| `GET /api/admin/users/{user}/tax-document/download` | Authorized owner/administrator attachment download |

Summaries contain the document ID, safe original filename, MIME type, size, submission date and `can_download`. They do not contain a storage path, public URL, notes or checksum. Responses use `Cache-Control: private, no-store`; attachments also use `nosniff` and a restrictive content security policy. The photographer account page fetches this summary separately from profile data and downloads an authenticated blob. Its temporary browser object URL is revoked after use. It does not retain document information in the general authentication profile.

General user serialization strips every normalized `tax_document*` / `taxDocument*` metadata key recursively, including on administrator and photographer list responses. Ordinary registration, profile and administrator metadata writes reject these fields, including nested preferences and JSON metadata. Unrelated metadata, photographer settings and profile fields keep their existing behavior. Until migration completes, unrelated updates preserve the old database migration pointers without serializing them.

## Upload replacement and recovery

Uploads and legacy migration share a per-user cache lock. The lock store must support atomic locks and be shared across application workers. The service rechecks account eligibility under the lock and database transaction. It writes a new private copy and verifies its SHA-256 before replacing the database reference. Only after commit does it remove the preceding private copy. A failure before commit retains the previous record and file and removes the uncommitted copy. A post-commit cleanup failure leaves an inaccessible private orphan and an aggregate operator log entry; it does not roll back the accepted submission.

An existing legacy public reference or pending legacy cleanup receipt blocks replacement with HTTP 409. This keeps a new upload from destroying the reference needed to remove the old public copy. Secure the legacy document first. If a browser upload response is interrupted, reload the dedicated summary before trying again: the upload may have committed even though the browser did not receive its response.

## Deployment and legacy migration

No production migration, file movement, storage inspection or web-server change was performed as part of this implementation. Run these operations only in the authorized deployment window, with a verified backup/recovery path for both the database and private storage.

1. Block `/storage/tax-documents` and every alternate direct public path to the same directory at the web server/object origin **before** deploying the new upload flow. The rule must cover the directory itself, children, case/normalization variants accepted by the server, and direct origin access. Keep this block permanently. Invalidate previously cached copies at any CDN/cache in front of the origin. An application controller cannot protect a file that the web server serves directly.
2. Provision the private disk permissions and shared lock store. Deploy the table migration, backend endpoints/filtering and photographer UI together. Do not create a symlink to private storage. Existing database `APP_KEY` encryption must remain readable; changing that key is a separate controlled operation.
3. Inspect the aggregate dry run from the backend release directory:

   ```sh
   php artisan tax-documents:privatize
   ```

   The default changes no document files or database records. It scans active and soft-deleted users, checks owner-specific legacy paths, rejects traversal and symlink sources, checks the existing private copy where present, and counts missing, invalid, conflicting and orphan files. It prints counts only, never names, URLs, paths, notes or file contents. A nonzero exit means operator review is required; it does not mean public access may be restored.
4. After reviewing the report and confirming the public prefix is blocked, explicitly apply:

   ```sh
   php artisan tax-documents:privatize --apply
   ```

   Each eligible source is copied to private storage, size and SHA-256 are compared, and the new database row is committed with a cleanup receipt. The command then re-verifies the public source, deletes it, removes old user metadata and clears the receipt. A source that changed, a missing/invalid private copy, an unowned path or a failed verification is never deleted as successful cleanup. Re-running safely resumes a committed copy's cleanup without creating another submission.
5. Run the dry run again. Resolve every missing, invalid, conflicting, failed and orphan entry through restricted operator review. Unreferenced public files are reported but never guessed to belong to a user or automatically deleted. Ambiguous sources remain blocked. Older DOC/DOCX documents may be migrated when their content type is recognized; new uploads accept only PDF/PNG/JPG. Unsupported, oversized or ambiguous legacy formats require operator review and a valid owner replacement after the legacy source is secured.
6. Verify with a synthetic document: the owner and a primary administrator can download it as an attachment; an unrelated user, sales account, impersonation session and unauthenticated request cannot. Verify the old public prefix fails at both the edge and origin. Confirm normal photographer settings, profile photo and preference updates still work. Keep the permanent public-prefix block in any application rollback; do not roll back to public tax uploads.

After migration, backups may still contain historical public files or metadata. Apply the organization's restricted-access and retention rules to these backups separately. Do not place tax documents or notes in ordinary support tickets, logs, browser telemetry or broad-access artifacts.

## Validation

The permission correction passed 30 portable tests / 156 assertions with three Unix-only skips. A separate isolated fixture executed with the production host's PHP 8.3 runtime passed all 12 checks for restrictive umask, file/directory modes, shared-group inheritance and rejection of incorrect existing permissions. It loaded candidate code and vendor only, without production application bootstrap, database access or real documents.

All validation used synthetic files, isolated SQLite and fake public/private disks; HTTP calls were forbidden. `TaxDocumentPrivacyTest`, `TaxDocumentMigrationTest` and existing `Auth/UpdateProfileTest` passed together: **25 tests, 180 assertions**. Coverage includes role isolation, guest/inactive/impersonation denial, authenticated attachment headers, false file types, oversized files, replacement failure, metadata write/serialization filtering, null metadata preservation, migration dry run/idempotency, owner/path validation, failed copy verification, changed public sources, missing private copies, retry receipts and deleted owners.

Frontend `TaxDocumentCard.test.tsx` and `taxDocuments.test.ts` passed: **8 tests**. They cover authenticated summary/blob requests, temporary URL cleanup, impersonation, replacement, input validation and safe failure messages. TypeScript project build also passed. Production web-server rules, backups, filesystem permissions and real-data migration remain deployment checks.

`TaxDocumentStoragePermissionsTest` additionally checks the configured converter and, on Unix filesystems, creation under umask `0077`, inherited group, exact file/directory modes, existing-directory validation and rejection of an unprovisioned root. The three actual Unix mode tests skip on Windows and must be run on a Unix filesystem before relying on cross-process group access.
