<?php

namespace App\Services;

use App\Models\User;

/** Keep provider credentials/deliverable URLs out of view-only shoot payloads. */
class IguideDataVisibilityService
{
    public function canManage(?User $user): bool
    {
        $role = strtolower(str_replace(['_', '-', ' '], '', (string) ($user?->role ?? '')));

        return in_array($role, ['admin', 'superadmin', 'editingmanager'], true);
    }

    /** @return array<string,mixed>|null */
    public function forUser(mixed $data, ?User $user): ?array
    {
        $data = is_array($data) ? $data : [];
        if ($this->canManage($user)) {
            return $data !== [] ? $this->operatorData($data) : null;
        }

        $package = $this->safePackage($data['manual_offline_package'] ?? null);
        $visible = array_filter([
            // View-state URLs are intentionally retained so clients can open
            // the tour inline. Standalone credentials, operator/manage links,
            // deliverable downloads, billing and provider IDs are excluded.
            'tour_url' => $this->safeHttpUrl($data['tour_url'] ?? null),
            'unbranded_url' => $this->safeHttpUrl($data['unbranded_url'] ?? null),
            'embedded_url' => $this->safeHttpUrl($data['embedded_url'] ?? null),
            'embed_image_url' => $this->safeHttpUrl($data['embed_image_url'] ?? null),
            'manual_offline_package' => $package,
        ], static fn (mixed $value): bool => $value !== null);

        return $visible !== [] ? $visible : null;
    }

    /** Keep operator workflow fields while removing stored offline diagnostics. */
    public function operatorData(array $data): array
    {
        if (is_array($data['manual_offline_package'] ?? null)) {
            $data['manual_offline_package'] = $this->operatorPackage($data['manual_offline_package']);
        }

        return $data;
    }

    public function operatorPackage(array $package): array
    {
        foreach ($package as $key => $value) {
            if ($key === 'error') {
                $package[$key] = self::publicOfflineFailure($value);
            } elseif (is_array($value)) {
                $package[$key] = $this->operatorPackage($value);
            }
        }

        return $package;
    }

    public static function publicOfflineFailure(mixed $value): ?string
    {
        if (in_array($value, [
            'The package could not be queued for assembly. Please retry.',
            'The resumable upload expired before it was completed.',
            'The package could not be assembled after multiple attempts.',
            'The assembled iGUIDE ZIP failed validation.',
            'The package could not be scanned.',
            'The clean package could not be finalized.',
            'The package could not be stored.',
            'The package could not be stored. Please try again.',
            'The uploaded package did not pass its security scan.',
            'The stored package could not be found for malware scanning.',
            'The package malware scan did not complete within the recovery window.',
            // Fixed structural validation messages contain no ZIP member paths
            // or provider values and explain how an uploader can correct a file.
            'The iGUIDE offline package must be a .zip file.',
            'The uploaded ZIP could not be read.',
            'The ZIP must be no larger than 256 MiB.',
            'The uploaded file is not a valid ZIP archive.',
            'The ZIP archive is malformed or inconsistent.',
            'The ZIP contains an unsafe number of entries.',
            'The ZIP contains an unreadable directory entry.',
            'The ZIP contains duplicate or case-colliding paths.',
            'The ZIP may contain at most 5,000 files.',
            'A ZIP entry exceeds the 256 MiB per-file limit.',
            'The ZIP contains an entry with an unsafe compression ratio.',
            'The expanded ZIP may not exceed 1 GiB.',
            'The ZIP must contain at least one file.',
            'The ZIP has an unsafe overall compression ratio.',
            'The ZIP contains a file/directory path collision.',
            'The ZIP must contain exactly one root index.html, optionally inside one wrapper folder.',
            'The uploaded ZIP could not be fingerprinted.',
            'Another iGUIDE package is already being scanned for this shoot.',
            'The ZIP contains an invalid path name.',
            'The ZIP contains an absolute or non-portable path.',
            'The ZIP contains an unsafe path name.',
            'The ZIP contains an invalid root entry.',
            'The ZIP contains a traversal or ambiguous path.',
            'Symbolic links are not allowed in the ZIP.',
            'Encrypted ZIP entries are not allowed.',
            'The ZIP contains a server configuration file.',
            'Nested archives are not allowed in the ZIP.',
            'The ZIP contains a server-executable or dangerous file type.',
            'The ZIP contains an unreadable file entry.',
            'The ZIP contains case-colliding directory paths.',
            'The assembled ZIP checksum did not match the expected file.',
            'File exceeds maximum allowed size',
            'File type not allowed',
        ], true)) {
            return $value;
        }

        return ApiErrorResponder::storedFailure(
            $value, 'The offline package could not be processed. Retry the upload or contact support.',
        );
    }

    /** @return array<string,mixed>|null */
    public function safePackage(mixed $package): ?array
    {
        if (! is_array($package)) {
            return null;
        }

        return array_filter([
            'status' => is_string($package['status'] ?? null) ? $package['status'] : null,
            'view_only' => true,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function safeHttpUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $url = trim($value);
        $parts = $url === '' ? false : parse_url($url);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }
}
