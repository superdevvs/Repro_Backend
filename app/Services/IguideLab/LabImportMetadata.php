<?php

namespace App\Services\IguideLab;

use Illuminate\Validation\ValidationException;

/** Inert JSON only. Archive scripts and viewer code are never evaluated. */
class LabImportMetadata
{
    public const VERSION = 'scan-import-json-1';
    public const MAX_BYTES = 8 * 1024 * 1024;

    public function parse(string $bytes): array
    {
        $this->require(strlen($bytes) <= self::MAX_BYTES, 'Scan metadata exceeds 8 MiB.');
        $text = ltrim($bytes, "\xEF\xBB\xBF \r\n\t");
        if (! str_starts_with($text, '{')) {
            $this->require(preg_match_all('/\bviewObject\s*:\s*(\{)/', $text, $matches, PREG_OFFSET_CAPTURE) === 1, 'This viewer does not contain one supported inert viewObject JSON record.');
            $start = $matches[1][0][1]; $depth = 0; $quoted = false; $escaped = false; $end = null;
            for ($i = $start, $n = strlen($text); $i < $n; $i++) {
                $char = $text[$i];
                if ($quoted) { if ($escaped) $escaped = false; elseif ($char === '\\') $escaped = true; elseif ($char === '"') $quoted = false; continue; }
                if ($char === '"') $quoted = true;
                elseif ($char === '{') $depth++;
                elseif ($char === '}' && --$depth === 0) { $end = $i + 1; break; }
            }
            $this->require($end !== null, 'The viewer JSON is incomplete.');
            $text = substr($text, $start, $end - $start);
        }
        try { $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $this->require(false, 'Scan metadata must be strict JSON, not executable JavaScript.'); }
        $this->require(is_array($data), 'Scan metadata must be a JSON object.');
        if (isset($data['panoramas'])) return $this->generic($data);
        return $this->iguide($data);
    }

    private function generic(array $data): array
    {
        $this->require(($data['version'] ?? null) === 1 && is_array($data['panoramas']) && count($data['panoramas']) <= 80, 'Use a version 1 panorama manifest with at most 80 entries.');
        $entries = [];
        foreach ($data['panoramas'] as $entry) {
            $this->require(is_array($entry) && is_string($entry['file'] ?? null) && strlen($entry['file']) <= 2048, 'Every manifest panorama needs an exact archive file path.');
            $file = $entry['file'];
            $this->require(! isset($entries[$file]), 'A panorama path is repeated in the manifest.');
            $entries[$file] = $this->fields($entry) + ['authority' => 'supplied_manifest'];
        }
        return ['kind' => 'generic_manifest', 'tourId' => null, 'propertyName' => $this->text($data['propertyName'] ?? null, 160), 'byFile' => $entries, 'byPano' => []];
    }

    private function iguide(array $data): array
    {
        $view = $data['viewObject'] ?? $data;
        $buildingData = $view['dataV2']['buildings'] ?? null;
        $this->require(is_array($buildingData) && count($buildingData) <= 30, 'Unsupported iGUIDE metadata: dataV2 buildings are required.');
        $tour = $this->text($view['iguide']['id'] ?? $view['id'] ?? null, 100);
        $this->require($tour !== null, 'The iGUIDE metadata is missing its source identity.');
        $panos = [];
        foreach ($buildingData as $building) {
            $this->require(is_array($building) && is_array($building['floors'] ?? null) && count($building['floors']) <= 100, 'Invalid iGUIDE floor metadata.');
            foreach ($building['floors'] as $floor) {
                $this->require(is_array($floor) && is_array($floor['layout'] ?? null) && is_array($floor['layout']['rooms'] ?? null) && count($floor['layout']['rooms']) <= 1000, 'Invalid iGUIDE room metadata.');
                $floorId = $this->id($floor['id'] ?? null); $rooms = [];
                foreach ($floor['layout']['rooms'] ?? [] as $room) {
                    $this->require(is_array($room), 'Invalid iGUIDE room record.');
                    $id = $this->id($room['id'] ?? null);
                    if ($id !== null) { $this->require(! isset($rooms[$id]), 'Duplicate iGUIDE room ID.'); $rooms[$id] = $this->text($room['name'] ?? null, 160); }
                }
                $floorPanos = $floor['layout']['panos'] ?? $floor['panos'] ?? null;
                $this->require(is_array($floorPanos), 'Invalid iGUIDE panorama metadata.');
                foreach ($floorPanos as $pano) {
                    $this->require(is_array($pano), 'Invalid iGUIDE panorama record.');
                    $id = $this->id($pano['id'] ?? null); $roomId = $this->id($pano['roomId'] ?? null);
                    $this->require($id !== null && ! isset($panos[$id]), 'Missing or ambiguous iGUIDE panorama ID.');
                    $this->require($roomId === null || array_key_exists($roomId, $rooms), 'A panorama references an unknown iGUIDE room.');
                    $panos[$id] = ['panoId' => $id, 'roomId' => $roomId, 'roomName' => $roomId === null ? null : $rooms[$roomId],
                        'floorId' => $floorId, 'floorName' => $this->text($floor['name'] ?? null, 160), 'buildingId' => $this->id($building['id'] ?? null),
                        'authority' => 'iguide_viewObject', 'camera' => $this->camera($pano)];
                    $this->require(count($panos) <= 500, 'The tour metadata contains too many panoramas.');
                }
            }
        }
        $this->require(count($panos) > 0, 'The iGUIDE metadata has no panoramas.');
        $property = $view['iguide']['property'] ?? []; $this->require(is_array($property), 'Invalid source property metadata.');
        $address = array_filter(array_map(fn ($key) => $this->text($property[$key] ?? null, 160), ['house', 'street', 'city']), fn ($value) => $value !== null);
        return ['kind' => 'iguide_viewObject', 'tourId' => $tour, 'alias' => $this->text($view['iguide']['alias'] ?? null, 200),
            'propertyName' => $address ? mb_substr(implode(' ', $address), 0, 160) : null, 'byPano' => $panos, 'byFile' => []];
    }

    private function camera(array $pano): array
    {
        $result = [];
        foreach (['rot', 'cameraHeight', 'initAngle'] as $key) if (isset($pano[$key]) && is_numeric($pano[$key]) && is_finite((float) $pano[$key])) $result[$key] = (float) $pano[$key];
        if (isset($pano['pos'])) {
            $this->require(is_array($pano['pos']) && array_is_list($pano['pos']) && count($pano['pos']) === 2
                && is_numeric($pano['pos'][0]) && is_numeric($pano['pos'][1]) && is_finite((float) $pano['pos'][0]) && is_finite((float) $pano['pos'][1]), 'Invalid source camera position.');
            $result['pos'] = array_map('floatval', $pano['pos']);
        }
        return $result;
    }
    private function fields(array $entry): array
    {
        return ['panoId' => $this->id($entry['panoId'] ?? null), 'roomId' => $this->id($entry['roomId'] ?? null),
            'roomName' => $this->text($entry['roomName'] ?? null, 160), 'floorId' => $this->id($entry['floorId'] ?? null),
            'floorName' => $this->text($entry['floorName'] ?? null, 160), 'buildingId' => null, 'camera' => []];
    }
    private function id(mixed $value): ?string { return $this->text(is_int($value) ? (string) $value : $value, 100); }
    private function text(mixed $value, int $limit): ?string
    {
        if ($value === null || $value === '') return null;
        $this->require(is_string($value) && mb_strlen($value) <= $limit && ! preg_match('/[\x00-\x1f\x7f]/u', $value), 'Invalid scan metadata value.');
        return $value;
    }
    private function require(bool $condition, string $message): void { if (! $condition) throw ValidationException::withMessages(['source' => $message]); }
}
