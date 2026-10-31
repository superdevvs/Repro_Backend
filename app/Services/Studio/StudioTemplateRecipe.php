<?php

namespace App\Services\Studio;

/** Reusable settings only; no selected assets, approvals or execution state. */
final class StudioTemplateRecipe
{
    public const PRESETS = [
        'listing-ready', 'color-correction', 'twilight', 'green-grass', 'virtual-staging',
        'full-shoot', 'sky-replacement', 'perspective-correction', 'upscale',
        'walkthrough', 'property-reel', 'social-teaser',
    ];

    private const SOURCE_KEYS = [
        'id', 'media', 'source', 'sources', 'image', 'images', 'photos', 'files', 'sourcemedia', 'sourceimages', 'selectedmedia', 'selectedimages',
        'mediaid', 'mediaids', 'mediaref', 'mediarefs', 'sourceid', 'sourceids', 'fileid', 'fileids',
        'shootid', 'shootids', 'workspaceid', 'studioworkspaceid', 'studioworkspace',
        'projectid', 'outputid', 'outputids', 'outputs', 'preparedframes', 'frames',
        'reviewedoutputids', 'reviewedframeids', 'photogroups', 'referenceimages', 'referencemediaids',
        'musicid', 'audioid', 'assetid', 'assetids', 'uploadid', 'uploadids',
        'url', 'uri', 'path', 'disk', 'sourcedisk', 'storagedisk', 'storagekey',
        'studioruntime', 'studioproviderroute', 'providerjobid', 'jobid', 'generation', 'generationscope',
        'requestid', 'teamid', 'createdby', 'userid', 'accesstoken', 'apikey',
    ];

    public static function workflow(string $preset): string
    {
        return match ($preset) {
            'twilight' => 'twilight',
            'walkthrough', 'property-reel', 'social-teaser' => 'reel-generator',
            default => 'photo-enhancement',
        };
    }

    public static function sanitize(array $config): array
    {
        $result = [];
        foreach ($config as $key => $value) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
            if (in_array($normalized, self::SOURCE_KEYS, true)
                || str_ends_with($normalized, 'url') || str_ends_with($normalized, 'uri')
                || str_ends_with($normalized, 'path')) {
                continue;
            }
            $result[$key] = is_array($value) ? self::sanitize($value) : $value;
            if ($normalized === 'audio' && is_array($result[$key])) {
                $result[$key]['musicId'] = null;
            }
        }

        return $result;
    }
}
