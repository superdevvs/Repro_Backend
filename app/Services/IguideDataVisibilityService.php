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
            return $data !== [] ? $data : null;
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
