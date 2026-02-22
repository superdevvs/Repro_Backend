<?php

namespace App\Services\BrightMls;

class LegacyBrightMlsStrategy implements BrightMlsStrategyInterface
{
    private string $apiUrl;
    private string $importUrlBase;

    public function __construct(string $apiUrl, string $importUrlBase)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->importUrlBase = rtrim($importUrlBase, '/');
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
        $errors = [];

        if (empty($payload['listItems']) || !is_array($payload['listItems'])) {
            return ['At least one media item is required to publish a manifest'];
        }

        foreach ($payload['listItems'] as $index => $item) {
            $itemIndex = $index + 1;
            $mediaType = $item['mediaType'] ?? null;
            $description = (string) ($item['description'] ?? '');

            if (strlen($description) > 50) {
                $errors[] = "Item {$itemIndex}: description must be 50 characters or fewer.";
            }

            if ($mediaType === 'photo') {
                $fileName = (string) ($item['fileName'] ?? '');
                if (!$this->isJpgFile($fileName)) {
                    $errors[] = "Item {$itemIndex}: photo fileName must end with .jpg or .jpeg.";
                }

                $fullSizeUrl = $item['imageUrls']['fullSize'] ?? null;
                if (!$this->isValidHttpUrl($fullSizeUrl)) {
                    $errors[] = "Item {$itemIndex}: photo imageUrls.fullSize must be a valid URL.";
                }
            }

            if ($mediaType === 'document' || $mediaType === 'floor_plan') {
                $fileName = (string) ($item['fileName'] ?? '');
                if (!$this->isPdfFile($fileName)) {
                    $errors[] = "Item {$itemIndex}: {$mediaType} fileName must end with .pdf.";
                }

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
                } elseif (!$this->isUnbrandedTourUrl($tourUrl)) {
                    $errors[] = "Item {$itemIndex}: tour_url must be an unbranded URL.";
                }
            }
        }

        return $errors;
    }

    public function buildImportUrl(string $manifestId): string
    {
        return $this->importUrlBase . '/Keystone/#ImportPhotos:' . $manifestId;
    }

    private function isJpgFile(string $fileName): bool
    {
        return (bool) preg_match('/\.jpe?g$/i', $fileName);
    }

    private function isPdfFile(string $fileName): bool
    {
        return (bool) preg_match('/\.pdf$/i', $fileName);
    }

    private function isValidHttpUrl(?string $value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (!in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function isUnbrandedTourUrl(string $tourUrl): bool
    {
        $parsed = parse_url($tourUrl);
        if (!$parsed || empty($parsed['host'])) {
            return false;
        }

        $haystack = strtolower($tourUrl);
        $blockedTokens = [
            'branding=',
            'brand=',
            'branded',
            'agent=',
            'agentid=',
            'realtor=',
            'contact=',
            'logo=',
            'watermark=',
        ];

        foreach ($blockedTokens as $token) {
            if (str_contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }
}
