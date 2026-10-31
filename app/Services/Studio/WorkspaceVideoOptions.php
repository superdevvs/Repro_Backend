<?php

namespace App\Services\Studio;

use Illuminate\Validation\Rule;

/** Finishing controls only. They never select a different paid motion model. */
class WorkspaceVideoOptions
{
    public const RESOLUTIONS = ['720p', '1080p'];

    public static function defaults(): array
    {
        return ['resolution' => '1080p', 'audio' => ['original' => false, 'musicId' => null, 'musicVolume' => 0.35, 'fade' => true]];
    }

    public static function rules(): array
    {
        return [
            'config.resolution' => ['sometimes', Rule::in(self::RESOLUTIONS)],
            'config.audio' => ['sometimes', 'array:original,musicId,musicVolume,fade'],
            'config.audio.original' => ['sometimes', 'boolean'],
            'config.audio.musicId' => ['sometimes', 'nullable', 'uuid'],
            'config.audio.musicVolume' => ['sometimes', 'numeric', 'between:0,1'],
            'config.audio.fade' => ['sometimes', 'boolean'],
        ];
    }

    public static function normalize(array $config): array
    {
        $settings = array_replace_recursive(self::defaults(), array_intersect_key($config, self::defaults()));
        $settings['audio'] = array_intersect_key($settings['audio'], self::defaults()['audio']);
        $settings['audio']['original'] = (bool) $settings['audio']['original'];
        $settings['audio']['fade'] = (bool) $settings['audio']['fade'];
        $settings['audio']['musicVolume'] = (float) $settings['audio']['musicVolume'];

        return array_replace($config, $settings);
    }

    public static function dimensions(array $config): array
    {
        $short = ($config['resolution'] ?? '1080p') === '720p' ? 720 : 1080;

        return match ($config['ratio'] ?? '9:16') {
            '16:9' => [(int) ($short * 16 / 9), $short],
            '1:1' => [$short, $short],
            '4:5' => [$short, (int) ($short * 5 / 4)],
            default => [$short, (int) ($short * 16 / 9)],
        };
    }

    /** Catalog can expose this without coupling its controller to upload/storage internals. */
    public static function capabilities(): array
    {
        return ['resolutions' => self::RESOLUTIONS, 'defaultResolution' => '1080p', 'format' => 'mp4',
            'durationSeconds' => ['min' => 5, 'max' => 120], 'maxFrames' => 12,
            'audio' => ['original' => 'when_clip_has_audio', 'defaultOriginal' => false, 'bundledMusic' => false,
                'workspaceUpload' => true, 'uploadField' => 'audio', 'uploadMaxBytes' => WorkspaceVideoAudio::MAX_BYTES,
                'uploadExtensions' => WorkspaceVideoAudio::EXTENSIONS, 'maxDurationSeconds' => 600,
                'musicVolume' => ['min' => 0, 'max' => 1, 'default' => 0.35], 'fade' => true]];
    }
}
