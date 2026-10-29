<?php

namespace App\Support;

use App\Models\Service;
use Illuminate\Validation\ValidationException;

/** Explicit package contents own camera-upload capabilities; names never grant access. */
final class ServiceInclusions
{
    public static function rules(): array
    {
        return [
            'included_components' => 'sometimes|array|max:20',
            'included_components.*' => 'array:name,upload_intake_type,uses_hdr_brackets',
            'included_components.*.name' => 'required|string|max:120',
            'included_components.*.upload_intake_type' => 'required|in:photo,video,photo_video,none',
            'included_components.*.uses_hdr_brackets' => 'required|boolean',
        ];
    }

    public static function normalize(array $components): array
    {
        $names = [];
        foreach ($components as $index => &$component) {
            $component['name'] = trim($component['name']);
            $key = mb_strtolower($component['name']);
            if ($key === '' || in_array($key, $names, true)) {
                throw ValidationException::withMessages(["included_components.{$index}.name" => 'Give each inclusion a unique name.']);
            }
            $names[] = $key;
            $component['uses_hdr_brackets'] = (bool) $component['uses_hdr_brackets'];
            if ($component['uses_hdr_brackets'] && ! self::hasPhotos($component)) {
                throw ValidationException::withMessages(["included_components.{$index}.uses_hdr_brackets" => 'HDR brackets require photo uploads.']);
            }
        }

        return array_values($components);
    }

    public static function capabilities(array $components): array
    {
        $photo = false;
        $video = false;
        $hdr = false;
        foreach ($components as $component) {
            $hasPhotos = self::hasPhotos($component);
            $photo = $photo || $hasPhotos;
            $video = $video || in_array($component['upload_intake_type'], [Service::INTAKE_VIDEO, Service::INTAKE_PHOTO_VIDEO], true);
            $hdr = $hdr || ($hasPhotos && $component['uses_hdr_brackets']);
        }

        return [
            'upload_intake_type' => $photo ? ($video ? Service::INTAKE_PHOTO_VIDEO : Service::INTAKE_PHOTO) : ($video ? Service::INTAKE_VIDEO : Service::INTAKE_NONE),
            'uses_hdr_brackets' => $hdr,
        ];
    }

    private static function hasPhotos(array $component): bool
    {
        return in_array($component['upload_intake_type'], [Service::INTAKE_PHOTO, Service::INTAKE_PHOTO_VIDEO], true);
    }
}
