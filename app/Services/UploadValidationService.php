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
     * Validate a single uploaded file against the configured maximum size and
     * allowed file-type list.
     *
     * @param  UploadedFile  $upload  the file to validate
     * @param  string  $field  the field name used in the thrown validation error
     *
     * @throws ValidationException  (rendered as HTTP 422) when the file is
     *                              oversize or of a disallowed type
     */
    public function validate(UploadedFile $upload, string $field = 'file'): void
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
    }

    /**
     * Validate a list of uploaded files, rejecting on the first failure.
     *
     * @param  iterable<UploadedFile>  $uploads
     *
     * @throws ValidationException
     */
    public function validateMany(iterable $uploads, string $field = 'file'): void
    {
        foreach ($uploads as $upload) {
            if ($upload instanceof UploadedFile) {
                $this->validate($upload, $field);
            }
        }
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
