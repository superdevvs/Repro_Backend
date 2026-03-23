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

        $errors = [];

        foreach ($payload['listItems'] as $index => $item) {
            $itemIndex = $index + 1;
            $mediaType = $item['mediaType'] ?? null;
            $description = (string) ($item['description'] ?? '');

            if (strlen($description) > 50) {
                $errors[] = "Item {$itemIndex}: description must be 50 characters or fewer.";
            }

            if ($mediaType === 'photo') {
                $fileName = (string) ($item['fileName'] ?? '');
                if (!preg_match('/\.jpe?g$/i', $fileName)) {
                    $errors[] = "Item {$itemIndex}: photo fileName must end with .jpg or .jpeg.";
                }

                $fullSizeUrl = $item['imageUrls']['fullSize'] ?? null;
                if (!$this->isValidHttpUrl($fullSizeUrl)) {
                    $errors[] = "Item {$itemIndex}: photo imageUrls.fullSize must be a valid URL.";
                }
            }

            if ($mediaType === 'document' || $mediaType === 'floor_plan') {
                $docUrl = $item['docUrl'] ?? null;
                if (!$this->isValidHttpUrl($docUrl)) {
                    $errors[] = "Item {$itemIndex}: {$mediaType} docUrl must be a valid URL.";
                }
            }

            if ($mediaType === 'tour_url') {
                $fileName = (string) ($item['fileName'] ?? '');
                if (strlen($fileName) > 25) {
                    $errors[] = "Item {$itemIndex}: tour_url fileName must be 25 characters or fewer.";
                }

                $tourUrl = $item['tourUrl'] ?? null;
                if (!$this->isValidHttpUrl($tourUrl)) {
                    $errors[] = "Item {$itemIndex}: tour_url tourUrl must be a valid URL.";
                }
            }
        }

        return $errors;
    }

    private function isValidHttpUrl(?string $value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        $parsed = parse_url($value);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        return in_array(strtolower($parsed['scheme']), ['http', 'https'], true);
    }

    public function buildImportUrl(string $manifestId): string
    {
        return $this->apiUrl . '/mlsredirect/bright/' . $manifestId;
    }
}
