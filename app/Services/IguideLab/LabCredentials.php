<?php

namespace App\Services\IguideLab;

use App\Exceptions\OpenAiImageException;
use App\Services\Studio\StudioProviderSettings;

class LabCredentials
{
    public function ready(): bool
    {
        // PHP-FPM may stat the worker-owned 0600 file, but cannot read its contents.
        $path = config('iguide-lab.openai_primary_key_path');
        return is_string($path) && is_file($path) && filesize($path) > 0;
    }

    public function primary(): string
    {
        $path = config('iguide-lab.openai_primary_key_path');
        $key = is_string($path) && is_file($path) && is_readable($path) ? trim((string) @file_get_contents($path)) : '';
        if ($key === '' || strlen($key) > 4096 || preg_match('/\s/', $key)) {
            throw new OpenAiImageException(reason: 'not_configured');
        }
        return $key;
    }

    /** Called only after an explicit primary quota rejection, never for transport/rate-limit errors. */
    public function quotaFallback(#[\SensitiveParameter] string $primary): ?string
    {
        $key = app(StudioProviderSettings::class)->credentials('openai')['api_key'] ?? null;
        return is_string($key) && filled($key) && ! hash_equals($primary, $key) ? $key : null;
    }
}
