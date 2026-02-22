<?php

namespace App\Services\BrightMls;

interface BrightMlsStrategyInterface
{
    public function buildManifest(array $manifestData): array;

    /**
     * @return array<int, string>
     */
    public function validatePayload(array $payload): array;

    public function buildImportUrl(string $manifestId): string;
}
