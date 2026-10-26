<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Saved documents reference immutable media IDs. Resolve them when rendering,
 * including after an editor tab has closed while a background job finishes. */
class EmailWorkspaceMediaResolver
{
    public function html(string $html, bool $preview): string
    {
        if (! str_contains($html, 'data-email-media-id')) return $html;
        $pattern = '~<span\b([^>]*\bdata-email-media-id\s*=\s*["\']([a-f0-9-]{36})["\'][^>]*)>.*?</span>~is';
        preg_match_all($pattern, $html, $matches);
        $assets = DB::table('email_template_assets')->whereIn('id', $matches[2])->get()->keyBy('id');
        return preg_replace_callback($pattern, function ($match) use ($assets, $preview) {
            $asset = $assets->get($match[2]);
            if ($asset?->status !== 'ready' || ! $asset->path) {
                if ($preview) return $match[0];
                throw ValidationException::withMessages(['template' => 'An email image is still processing or unavailable. Wait for it, or replace/remove that image before sending.']);
            }
            preg_match('~data-email-media-alt\s*=\s*(["\'])(.*?)\1~is', $match[1], $alt);
            return '<img src="'.e(EmailWorkspaceAssets::publicUrl($asset->path)).'" alt="'.e(html_entity_decode($alt[2] ?? $asset->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')).'" width="520" style="display:block;max-width:100%;height:auto;">';
        }, $html) ?? $html;
    }

    public function resolve(array $sections, bool $preview): array
    {
        $flat = $sections;
        foreach ($sections as $section) foreach ($section['columns'] ?? [] as $column) $flat = array_merge($flat, $column);
        $assets = DB::table('email_template_assets')->whereIn('id', array_filter(array_column($flat, 'media_id')))->get()->keyBy('id');
        $artwork = DB::table('email_template_artworks')->whereIn('id', array_filter(array_column($flat, 'artwork_id')))->get()->keyBy('id');
        $optimized = DB::table('email_template_assets')->whereIn('artwork_id', $artwork->keys())->where('status', 'ready')->get()->keyBy('artwork_id');
        $map = function ($section) use (&$map, $assets, $artwork, $optimized, $preview) {
            if (! empty($section['columns'])) $section['columns'] = array_map(fn ($column) => array_map($map, $column), $section['columns']);
            $media = ! empty($section['media_id']) ? $assets->get($section['media_id']) : null;
            $art = ! empty($section['artwork_id']) ? $artwork->get($section['artwork_id']) : null;
            if ($media?->status === 'ready' && $media->path) $section['image_url'] = EmailWorkspaceAssets::publicUrl($media->path);
            elseif ($art?->status === 'completed' && $art->path) $section['image_url'] = EmailWorkspaceAssets::publicUrl($optimized->get($art->id)?->path ?? $art->path);
            elseif (! $preview && ($media || $art || ((! empty($section['media_id']) || ! empty($section['artwork_id'])) && empty($section['image_url'])))) {
                throw ValidationException::withMessages(['template' => 'An email image is still processing or unavailable. Wait for it, or replace/remove that image before sending.']);
            }
            return $section;
        };
        return array_map($map, $sections);
    }
}
