<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WatermarkSettings extends Model
{
    use HasFactory;

    private const DEFAULT_LOGO_PATH = 'storage/watermark-logos/REPRO-HQ.png';
    private const LEGACY_LOGO_PATH = 'images/REPRO-HQ.png';

    protected $fillable = [
        'name',
        'is_active',
        'logo_enabled',
        'logo_position',
        'logo_opacity',
        'logo_size',
        'logo_offset_x',
        'logo_offset_y',
        'custom_logo_url',
        'text_enabled',
        'text_content',
        'text_style',
        'text_opacity',
        'text_color',
        'text_size',
        'text_spacing',
        'text_angle',
        'overlay_enabled',
        'overlay_color',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'logo_enabled' => 'boolean',
        'logo_opacity' => 'integer',
        'logo_size' => 'decimal:2',
        'logo_offset_x' => 'integer',
        'logo_offset_y' => 'integer',
        'text_enabled' => 'boolean',
        'text_opacity' => 'integer',
        'text_size' => 'integer',
        'text_spacing' => 'integer',
        'text_angle' => 'integer',
        'overlay_enabled' => 'boolean',
    ];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function getActive(): ?self
    {
        return self::where('is_active', true)->first();
    }

    public static function getDefault(): self
    {
        $settings = self::getActive() ?? self::firstOrCreate(
            ['name' => 'default'],
            [
                'is_active' => true,
                'logo_enabled' => true,
                'logo_position' => 'bottom-right',
                'logo_opacity' => 60,
                'logo_size' => 20,
                'logo_offset_x' => 3,
                'logo_offset_y' => 8,
            ]
        );

        $defaultLogoUrl = self::defaultLogoUrl();
        $legacyLogoPath = public_path(self::LEGACY_LOGO_PATH);

        if (
            empty($settings->custom_logo_url)
            || (
                str_contains((string) $settings->custom_logo_url, '/' . self::LEGACY_LOGO_PATH)
                && !file_exists($legacyLogoPath)
            )
        ) {
            $settings->custom_logo_url = $defaultLogoUrl;
            $settings->save();
        }

        return $settings;
    }

    public static function defaultLogoUrl(): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        $storageLogoPath = storage_path('app/public/' . ltrim(str_replace('storage/', '', self::DEFAULT_LOGO_PATH), '/'));
        $legacyLogoPath = public_path(self::LEGACY_LOGO_PATH);

        if (file_exists($storageLogoPath)) {
            $path = self::DEFAULT_LOGO_PATH;
        } elseif (file_exists($legacyLogoPath)) {
            $path = self::LEGACY_LOGO_PATH;
        } else {
            $path = self::DEFAULT_LOGO_PATH;
        }

        return $baseUrl ? $baseUrl . '/' . $path : '/' . $path;
    }

    public function calculateLogoPosition(int $imageWidth, int $imageHeight, int $logoWidth, int $logoHeight): array
    {
        $offsetX = (int)($imageWidth * ($this->logo_offset_x / 100));
        $offsetY = (int)($imageHeight * ($this->logo_offset_y / 100));

        switch ($this->logo_position) {
            case 'top-left':
                return ['x' => $offsetX, 'y' => $offsetY];
            case 'top-right':
                return ['x' => $imageWidth - $logoWidth - $offsetX, 'y' => $offsetY];
            case 'bottom-left':
                return ['x' => $offsetX, 'y' => $imageHeight - $logoHeight - $offsetY];
            case 'bottom-right':
            default:
                return ['x' => $imageWidth - $logoWidth - $offsetX, 'y' => $imageHeight - $logoHeight - $offsetY];
            case 'center':
                return [
                    'x' => (int)(($imageWidth - $logoWidth) / 2),
                    'y' => (int)(($imageHeight - $logoHeight) / 2)
                ];
        }
    }
}
