<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Pre-scan upload validation (Req 14.5, 14.6).
 *
 * Consolidates the previously inline size + type checks from
 * FileUploadController into a single reusable service so that every upload
 * path can reject oversize or disallowed-type files identically — with an
 * HTTP 422 response — BEFORE any ShootFile row is created or any scan job is
 * enqueued.
 *
 * Limits are sourced from config/uploads.php, which mirrors the historical
 * FileUploadController rules (`max:1048576` KB and the photo/video `mimes:`
 * allow-list), keeping behaviour consistent with what already exists.
 *
 * This service performs NO I/O and has no side effects, so it is safe to call
 * up front and is directly unit-testable.
 */
class UploadValidationService
{
    /**
     * Archive container extensions. ClamAV scans an archive as a single opaque
     * object and never inspects the individual files inside it, so archive
     * CONTENTS are effectively unscanned (Req 5.9). Uploads of these types are
     * therefore restricted to authenticated staff accounts (see isStaffRole).
     *
     * @var list<string>
     */
    public const ARCHIVE_EXTENSIONS = ['zip'];

    /**
     * Authenticated internal (staff) roles permitted to upload archives. Any
     * role not in this list — notably clients and unauthenticated callers — is
     * refused archive uploads.
     *
     * @var list<string>
     */
    public const STAFF_ROLES = [
        'admin',
        'superadmin',
        'editing_manager',
        'editor',
        'photographer',
        'salesRep',
        'sales_rep',
        'finance',
        'accounting',
    ];

    /**
     * Validate a single uploaded file against the configured maximum size and
     * allowed file-type list.
     *
     * @param  UploadedFile  $upload  the file to validate
     * @param  string  $field  the field name used in the thrown validation error
     * @param  string|null  $userRole  the authenticated uploader's role, used to
     *                                 gate archive uploads to staff (Req 5.9)
     *
     * @throws ValidationException  (rendered as HTTP 422) when the file is
     *                              oversize, of a disallowed type, or an archive
     *                              uploaded by a non-staff account
     */
    public function validate(UploadedFile $upload, string $field = 'file', ?string $userRole = null): void
    {
        $maxBytes = $this->maxBytes();
        $size = $upload->getSize();

        // getSize() returns the size in bytes (or false if it could not be
        // determined). Only reject when we positively know the file is too big.
        if (is_int($size) && $size > $maxBytes) {
            throw ValidationException::withMessages([
                $field => 'File exceeds maximum allowed size',
            ]);
        }

        if (! $this->isAllowedType($upload)) {
            throw ValidationException::withMessages([
                $field => 'File type not allowed',
            ]);
        }

        // Archive uploads carry files ClamAV cannot see (it scans the container,
        // not its contents), so they are restricted to authenticated staff
        // accounts (Req 5.9). A client or unauthenticated caller is refused.
        if ($this->isArchiveUpload($upload) && ! $this->isStaffRole($userRole)) {
            throw ValidationException::withMessages([
                $field => 'Archive (.zip) uploads are restricted to staff accounts',
            ]);
        }

        // Defence-in-depth (QA #14): reject files whose real content type (detected
        // via magic bytes) is an executable/script even when the extension is in the
        // allow-list. This catches a renamed binary (e.g. malware.exe -> photo.png).
        if ($this->hasDangerousContentType($upload)) {
            throw ValidationException::withMessages([
                $field => 'File content does not match an allowed media type',
            ]);
        }
    }

    /**
     * Whether the upload's detected MIME type is a known-dangerous executable or
     * script type. Uses the finfo-backed detected type (not the client-supplied
     * one), so a spoofed extension cannot bypass it.
     */
    public function hasDangerousContentType(UploadedFile $upload): bool
    {
        try {
            $mime = strtolower((string) $upload->getMimeType());
        } catch (\Throwable) {
            // If the type cannot be detected, defer to the scan stage rather than
            // blocking a legitimate upload here.
            return false;
        }

        if ($mime === '') {
            return false;
        }

        $dangerous = [
            'application/x-dosexec',
            'application/x-msdownload',
            'application/x-executable',
            'application/x-elf',
            'application/x-mach-binary',
            'application/x-sharedlib',
            'application/vnd.microsoft.portable-executable',
            'application/x-msdos-program',
            'application/x-php',
            'text/x-php',
            'application/x-httpd-php',
            'application/x-perl',
            'application/x-python',
            'application/x-shellscript',
            'text/x-shellscript',
            'application/javascript',
            'application/x-bat',
            'application/hta',
        ];

        return in_array($mime, $dangerous, true);
    }

    /**
     * Validate a list of uploaded files, rejecting on the first failure.
     *
     * @param  iterable<UploadedFile>  $uploads
     *
     * @throws ValidationException
     */
    public function validateMany(iterable $uploads, string $field = 'file', ?string $userRole = null): void
    {
        foreach ($uploads as $upload) {
            if ($upload instanceof UploadedFile) {
                $this->validate($upload, $field, $userRole);
            }
        }
    }

    /**
     * Whether the upload is an archive container whose contents ClamAV cannot
     * individually scan (Req 5.9).
     */
    public function isArchiveUpload(UploadedFile $upload): bool
    {
        $extension = strtolower($upload->getClientOriginalExtension());

        return in_array($extension, self::ARCHIVE_EXTENSIONS, true);
    }

    /**
     * Whether the given role is an authenticated staff role permitted to upload
     * archives. A null/empty role (unauthenticated) is never staff.
     */
    public function isStaffRole(?string $role): bool
    {
        if ($role === null || trim($role) === '') {
            return false;
        }

        return in_array(
            strtolower(trim($role)),
            array_map('strtolower', self::STAFF_ROLES),
            true
        );
    }

    /**
     * Whether the upload's extension is in the configured allow-list.
     */
    public function isAllowedType(UploadedFile $upload): bool
    {
        $extension = strtolower($upload->getClientOriginalExtension());

        return in_array($extension, $this->allowedTypes(), true);
    }

    /**
     * The maximum allowed file size, in bytes.
     */
    public function maxBytes(): int
    {
        return (int) config('uploads.max_bytes', 1048576 * 1024);
    }

    /**
     * The list of allowed (lower-case) file extensions.
     *
     * @return list<string>
     */
    public function allowedTypes(): array
    {
        return array_map(
            'strtolower',
            (array) config('uploads.allowed_types', [])
        );
    }
}
