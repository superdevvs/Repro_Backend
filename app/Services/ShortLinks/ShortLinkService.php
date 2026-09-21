<?php

namespace App\Services\ShortLinks;

use App\Models\ShortLink;
use App\Support\LockedWrite;
use Illuminate\Support\Str;

class ShortLinkService
{
    public function __construct(protected ShortLinkSettings $settings)
    {
    }

    public function enabled(string $type): bool
    {
        return $this->settings->enabled($type);
    }

    public function remember(
        string $type,
        string $targetType,
        int $targetId,
        ?string $targetKey = null,
        ?int $createdBy = null
    ): ShortLink {
        $targetKey = $targetKey ?? '';

        return LockedWrite::run(
            function () use ($type, $targetType, $targetId, $targetKey, $createdBy): ShortLink {
                $existing = ShortLink::query()
                    ->where('type', $type)
                    ->where('target_type', $targetType)
                    ->where('target_id', $targetId)
                    ->where('target_key', $targetKey)
                    ->whereNull('revoked_at')
                    ->first();
                if ($existing) {
                    return $existing;
                }

                return ShortLink::create([
                    'code' => $this->uniqueCode(),
                    'type' => $type,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'target_key' => $targetKey,
                    'created_by' => $createdBy,
                ]);
            },
            "short-link.{$type}.{$targetType}.{$targetId}"
        );
    }

    public function url(ShortLink $link, ?string $path = null): string
    {
        $prefix = route('api.public.short-links.show', ['code' => $link->code]);
        if ($path === null || $path === '') {
            return $prefix;
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));

        return rtrim($prefix, '/').'/'.$encoded;
    }

    public function maybeShorten(
        string $type,
        string $targetType,
        int $targetId,
        string $fallbackUrl,
        ?string $targetKey = null,
        ?string $path = null
    ): string {
        if (! $this->enabled($type)) {
            return $fallbackUrl;
        }

        return $this->url($this->remember($type, $targetType, $targetId, $targetKey), $path);
    }

    public function findActive(string $code): ?ShortLink
    {
        if (preg_match('/^[A-Za-z0-9]{8,16}$/D', $code) !== 1) {
            return null;
        }

        $link = ShortLink::query()->where('code', $code)->first();
        if ($link === null || ! $link->isActive()) {
            return null;
        }

        return $link;
    }

    private function uniqueCode(): string
    {
        $length = $this->settings->codeLength();
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = Str::random($length);
            if (! ShortLink::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not allocate a unique short-link code.');
    }
}
