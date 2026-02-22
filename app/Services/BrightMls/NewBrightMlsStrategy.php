<?php

namespace App\Services\BrightMls;

class NewBrightMlsStrategy implements BrightMlsStrategyInterface
{
    private string $apiUrl;

    public function __construct(string $apiUrl)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
    }

    public function buildManifest(array $manifestData): array
    {
        $payload = [
            'vendorId' => $manifestData['vendorId'] ?? null,
            'vendorName' => $manifestData['vendorName'] ?? 'Repro Photos',
            'dateFileCreated' => $manifestData['dateFileCreated'] ?? now()->toIso8601String(),
            'listItems' => $manifestData['listItems'] ?? [],
        ];

        if (!empty($manifestData['propertyAddress'])) {
            $payload['propertyAddress'] = $manifestData['propertyAddress'];
        }

        if (!empty($manifestData['mlsId'])) {
            $payload['mlsId'] = $manifestData['mlsId'];
        }

        return $payload;
    }

    public function validatePayload(array $payload): array
    {
        if (empty($payload['listItems']) || !is_array($payload['listItems'])) {
            return ['At least one media item is required to publish a manifest'];
        }

        return [];
    }

    public function buildImportUrl(string $manifestId): string
    {
        return $this->apiUrl . '/mlsredirect/bright/' . $manifestId;
    }
}
