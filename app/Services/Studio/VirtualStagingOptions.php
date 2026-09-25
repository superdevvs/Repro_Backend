<?php

namespace App\Services\Studio;

use App\Exceptions\StudioProviderException;
use Illuminate\Validation\ValidationException;

/** Maps AI Editing controls onto the Virtual Staging AI v2 staging config. */
class VirtualStagingOptions
{
    public const ROOMS = [
        'living' => 'Living room',
        'bed' => 'Bedroom',
        'kitchen' => 'Kitchen',
        'dining' => 'Dining room',
        'home_office' => 'Home office',
        'outdoor' => 'Outdoor',
        'kids_room' => 'Kids room',
    ];

    public const STYLES = [
        'standard' => 'Standard',
        'modern' => 'Modern',
        'scandinavian' => 'Scandinavian',
        'industrial' => 'Industrial',
        'midcentury' => 'Mid-century',
        'luxury' => 'Luxury',
        'farmhouse' => 'Farmhouse',
        'coastal' => 'Coastal',
    ];

    private const ROOM_ALIASES = [
        'living' => 'living', 'living-room' => 'living', 'living room' => 'living',
        'bed' => 'bed', 'bedroom' => 'bed',
        'kitchen' => 'kitchen',
        'dining' => 'dining', 'dining-room' => 'dining', 'dining room' => 'dining',
        'home_office' => 'home_office', 'home-office' => 'home_office', 'office' => 'home_office',
        'outdoor' => 'outdoor',
        'kids_room' => 'kids_room', 'kids-room' => 'kids_room', 'kids room' => 'kids_room',
    ];

    private const STYLE_ALIASES = [
        'standard' => 'standard', 'traditional' => 'standard',
        'modern' => 'modern', 'editorial' => 'modern', 'contemporary' => 'modern',
        'scandinavian' => 'scandinavian', 'industrial' => 'industrial',
        'midcentury' => 'midcentury', 'mid-century' => 'midcentury', 'mid-century modern' => 'midcentury',
        'luxury' => 'luxury', 'farmhouse' => 'farmhouse', 'coastal' => 'coastal',
    ];

    public static function assert(array $adjustments): array
    {
        try {
            return self::normalize($adjustments);
        } catch (StudioProviderException $exception) {
            throw ValidationException::withMessages(['config.adjustments' => $exception->getMessage()]);
        }
    }

    public static function normalize(array $adjustments): array
    {
        $roomKey = strtolower(trim((string) ($adjustments['roomType'] ?? 'living')));
        $styleKey = strtolower(trim((string) ($adjustments['furnitureStyle'] ?? $adjustments['style'] ?? 'modern')));
        $room = self::ROOM_ALIASES[$roomKey] ?? $roomKey;
        $style = self::STYLE_ALIASES[$styleKey] ?? $styleKey;
        if (! isset(self::ROOMS[$room])) {
            throw new StudioProviderException('Choose a supported room type.');
        }
        if (! isset(self::STYLES[$style])) {
            throw new StudioProviderException('Choose a supported furniture style.');
        }
        $removal = strtolower(trim((string) ($adjustments['removal'] ?? 'off')));
        $removal = ['none' => 'off', 'remove' => 'on'][$removal] ?? $removal;
        if (! in_array($removal, ['off', 'auto', 'on'], true)) {
            throw new StudioProviderException('Choose how existing furniture should be handled.');
        }
        $addFurniture = self::bool($adjustments['addFurniture'] ?? true, true);
        if (! $addFurniture && $removal !== 'on') {
            throw new StudioProviderException('Turn furniture removal on when you only want the room emptied.');
        }
        $count = $adjustments['variationCount'] ?? 1;
        if (! is_numeric($count) || (int) $count < 1 || (int) $count > 20) {
            throw new StudioProviderException('Choose between 1 and 20 arrangements.');
        }
        $resolution = $adjustments['resolution'] ?? 'default';
        if ($resolution === '1536' || $resolution === 1536) {
            $resolution = 1536;
        } elseif (in_array($resolution, ['default', '4k', 'full-hd', 'full_hd'], true)) {
            $resolution = 'default';
        } else {
            throw new StudioProviderException('Choose a supported output size.');
        }

        return [
            'roomType' => $room,
            'style' => $style,
            'removal' => $addFurniture ? $removal : 'on',
            'addFurniture' => $addFurniture,
            'variationCount' => (int) $count,
            'watermark' => $addFurniture && self::bool($adjustments['watermark'] ?? false, false),
            'resolution' => $resolution,
            'useDetectedMask' => self::bool($adjustments['useDetectedMask'] ?? false, false) && ($addFurniture ? $removal !== 'off' : true),
        ];
    }

    /** @param  array{roomType: string, style: string, removal: string, addFurniture: bool, variationCount: int, watermark: bool, resolution: int|string, useDetectedMask: bool}  $options */
    public static function config(array $options, ?string $maskUrl, ?string $baseVariationId): array
    {
        $config = ['type' => 'staging'];
        if ($options['resolution'] === 1536) {
            $config['output_resolution'] = 1536;
        }
        if ($options['watermark']) {
            $config['add_virtually_staged_watermark'] = true;
        }
        if ($options['addFurniture']) {
            $furniture = ['style' => $options['style'], 'room_type' => $options['roomType']];
            if ($baseVariationId) {
                $furniture['base_variation_id'] = $baseVariationId;
            }
            $config['add_furniture'] = $furniture;
        }
        if ($options['removal'] !== 'off' && $baseVariationId === null) {
            $removal = ['mode' => $options['addFurniture'] ? $options['removal'] : 'on'];
            if ($maskUrl) {
                $removal['mask_url'] = $maskUrl;
            }
            $config['remove_furniture'] = $removal;
        }
        if (! isset($config['add_furniture']) && ! isset($config['remove_furniture'])) {
            throw new StudioProviderException('Choose furniture to add, furniture to remove, or both.');
        }

        return $config;
    }

    public static function label(string $type, ?string $style, ?string $room): string
    {
        if ($type === 'removal') {
            return 'Furniture removed';
        }
        $styleLabel = self::STYLES[$style] ?? 'Staged';
        $roomLabel = self::ROOMS[$room] ?? null;

        return trim('Staged · '.$styleLabel.($roomLabel ? ' '.$roomLabel : ''));
    }

    private static function bool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'off', ''], true)) {
                return $value === '' ? $default : false;
            }
        }

        return $default;
    }
}
