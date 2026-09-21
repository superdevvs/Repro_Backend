<?php

namespace App\Services\ShortLinks;

use App\Models\ShortLink;
use Illuminate\Support\Facades\DB;

class ShortLinkSettings
{
    public const KEY = 'short_links';

    /**
     * @return array{enabled: bool, code_length: int, types: array<string, bool>}
     */
    public function defaults(): array
    {
        return [
            'enabled' => (bool) config('short_links.enabled', true),
            'code_length' => $this->clampLength(config('short_links.code_length', 10)),
            'types' => $this->normalizeTypes((array) config('short_links.types', [])),
        ];
    }

    /**
     * @return array{enabled: bool, code_length: int, types: array<string, bool>}
     */
    public function current(): array
    {
        $defaults = $this->defaults();
        $stored = $this->stored();

        return [
            'enabled' => array_key_exists('enabled', $stored)
                ? (bool) $stored['enabled']
                : $defaults['enabled'],
            'code_length' => $this->clampLength($stored['code_length'] ?? $defaults['code_length']),
            'types' => array_replace(
                $defaults['types'],
                $this->normalizeTypes((array) ($stored['types'] ?? []))
            ),
        ];
    }

    public function enabled(string $type): bool
    {
        $current = $this->current();

        return $current['enabled'] && ($current['types'][$type] ?? false);
    }

    public function codeLength(): int
    {
        return $this->current()['code_length'];
    }

    /**
     * @param  array{enabled: bool, code_length: int, types: array<string, bool>}  $payload
     * @return array{enabled: bool, code_length: int, types: array<string, bool>}
     */
    public function save(array $payload): array
    {
        $normalized = [
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'code_length' => $this->clampLength($payload['code_length'] ?? 10),
            'types' => $this->normalizeTypes((array) ($payload['types'] ?? [])),
        ];

        $exists = DB::table('settings')->where('key', self::KEY)->exists();
        $row = [
            'value' => json_encode($normalized),
            'type' => 'json',
            'description' => 'First-party short links for public viewer, share, zip, and payment URLs.',
            'updated_at' => now(),
        ];
        if (! $exists) {
            $row['created_at'] = now();
        }

        DB::table('settings')->updateOrInsert(['key' => self::KEY], $row);

        return $this->current();
    }

    /**
     * @return list<string>
     */
    public function allowedTypes(): array
    {
        return [
            ShortLink::TYPE_IGUIDE_OFFLINE_VIEWER,
            ShortLink::TYPE_SHARE_DOWNLOAD,
            ShortLink::TYPE_MEDIA_ZIP,
            ShortLink::TYPE_PAYMENT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $value = DB::table('settings')->where('key', self::KEY)->value('value');
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $types
     * @return array<string, bool>
     */
    private function normalizeTypes(array $types): array
    {
        $normalized = [];
        foreach ($this->allowedTypes() as $type) {
            $normalized[$type] = array_key_exists($type, $types)
                ? (bool) $types[$type]
                : (bool) config('short_links.types.'.$type, false);
        }

        return $normalized;
    }

    private function clampLength(mixed $length): int
    {
        $value = is_numeric($length) ? (int) $length : 10;

        return max(8, min(12, $value));
    }
}
