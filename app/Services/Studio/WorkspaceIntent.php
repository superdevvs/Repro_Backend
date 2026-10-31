<?php

namespace App\Services\Studio;

/** A conservative, explainable plan. Resolving an intent never submits paid work. */
class WorkspaceIntent
{
    private const PHRASES = [
        'full-shoot' => '/\b(full[ -]shoot|entire shoot|whole shoot|all (?:the )?shoot photos)\b/i',
        'twilight' => '/\b(twilight|dusk|blue hour|day[ -]to[ -]night)\b/i',
        'green-grass' => '/\b(green[ -]grass|greener (?:grass|lawn)|(?:green|refresh|improve) (?:the |this )?(?:grass|lawn)|(?:grass|lawn) (?:look )?green(?:er)?)\b/i',
        'virtual-staging' => '/\b(virtual(?:ly)? stag(?:e|ing)|stag(?:e|ing) (?:this |the |my )?(?:room|home|house|bedroom|dining(?: room)?|kitchen|office|living(?: room)?)|(?:add|replace) (?:the |some )?furniture|furnish)\b/i',
        'sky-replacement' => '/\b(sky replacement|(?:replace|change) (?:the |this )?sky|(?:blue|clear) sky)\b/i',
        'perspective-correction' => '/\b(perspective correction|(?:fix|correct|straighten) (?:the )?(?:verticals?|perspective|lines)|lens distortion)\b/i',
        'upscale' => '/\b(upscale|upscaling|(?:increase|higher|improve) (?:the )?resolution)\b/i',
        'color-correction' => '/\b(colou?r correction|(?:fix|correct|balance) (?:the )?(?:colou?rs?|white balance))\b/i',
        'walkthrough' => '/\b(walk[ -]?through|property tour|camera (?:move|pan|movement)|slow(?:ly)? (?:move|pan|zoom)|dolly (?:in|out)|pan through)\b/i',
        'property-reel' => '/\b((?:property |photo |listing )?reel|(?:combine|turn|make).{0,40}photos.{0,30}(?:video|movie))\b/i',
        'social-teaser' => '/\b(social teaser|teaser|(?:instagram|tiktok|social) (?:story|preview|video))\b/i',
    ];

    public function __construct(private WorkspaceCatalog $catalog, private StudioProviderSettings $settings) {}

    public function resolve(array $input): array
    {
        $prompt = trim($input['prompt'] ?? '');
        $explicit = $input['presetId'] ?? null;
        $count = (int) ($input['mediaCount'] ?? 0);
        $matches = [];
        $negated = false;
        foreach (self::PHRASES as $id => $pattern) {
            if (preg_match($pattern, $prompt)) {
                $matches[] = $id;
                $negated = $negated || $this->effectIsNegated($prompt, $pattern);
            }
        }
        $videoPattern = '/\b(video|animate|animation|movie|clip)\b/i';
        if (! array_intersect($matches, WorkspaceCatalog::VIDEO_PRESETS) && preg_match($videoPattern, $prompt)) {
            $matches[] = $count > 1 ? 'property-reel' : 'walkthrough';
            $negated = $negated || $this->effectIsNegated($prompt, $videoPattern);
        }
        // The legacy config includes defaults. Native saved recipes instead merge
        // these sparse, prompt-derived fields recursively, preserving unmentioned
        // settings (especially private music IDs). Preset/mode remain separate.
        $overrides = $prompt !== '' ? ['prompt' => $prompt] : [];
        $base = ['status' => 'needs_input', 'presetId' => null, 'mode' => null, 'config' => ['prompt' => $prompt], 'configOverrides' => (object) $overrides, 'candidates' => [], 'requiresPreparation' => false, 'requiresConfirmation' => false];
        if (preg_match('/^(?:what(?:[\x{0027}\x{2019}]s| is| are| does)|who|where|when|why|how (?:does|do|is|are)|does (?:this|it) need|explain|describe|tell me)\b/iu', $prompt)) {
            return $base + ['reason' => 'not_an_edit', 'question' => 'This looks like a chat message. Continue in Robbie chat, or describe the edit you want.'];
        }
        $ids = array_values(array_unique(array_filter([$explicit, ...$matches])));
        $refinable = ['listing-ready', 'color-correction', 'twilight', 'green-grass', 'sky-replacement', 'perspective-correction'];
        $combinedPhoto = count($ids) > 1 && array_diff($ids, $refinable) === [];
        $alternative = count($ids) > 1 && $this->hasAlternativeEffects($prompt);
        // Compatible photo effects are handled together by the existing prompt
        // refinement path. Alternatives, cross-mode plans, and excluded effects
        // require a choice; unrelated preservation instructions stay intact.
        if ($negated || $alternative || (count($ids) > 1 && ! $combinedPhoto)) {
            $base['candidates'] = array_map(fn ($id) => ['presetId' => $id, 'label' => $this->catalog->label($id)], $ids);

            return $base + ['reason' => 'ambiguous_intent', 'question' => 'Which edit should I start with? Your instructions include different or excluded effects.'];
        }
        $preset = $explicit ?? ($matches[0] ?? null);
        if ($combinedPhoto && ! $explicit) {
            $preset = in_array('twilight', $ids, true) ? 'twilight' : 'listing-ready';
        }
        $photoEdit = preg_match('/\b(remove|erase|retouch|brighten|brighter|darken|darker|crop|straighten|sharpen|blur|enhance|edit|improve|replace|adjust|clean up|add)\b|\bmake\b.{0,30}\b(?:better|lighter|warmer|cooler|brighter|darker)\b/i', $prompt);
        $question = preg_match('/^(?:who|what|where|when|why|how|is|are|does|do|can|could|would|will|describe|explain|tell me)\b/i', $prompt);
        $greeting = preg_match('/^(?:hello|hi|hey|thanks|thank you)[!.\s]*$/i', $prompt);
        if (! $preset && $prompt !== '' && (($question && ! $photoEdit) || $greeting)) {
            return $base + ['reason' => 'not_an_edit', 'question' => 'This looks like a chat message. Continue in Robbie chat, or describe the edit you want.'];
        }
        if (! $preset && $prompt !== '' && ($input['mode'] ?? null) === 'video' && ! $photoEdit) {
            $preset = $count > 1 ? 'property-reel' : 'walkthrough';
        }
        if (! $preset && ($prompt !== '' || ($input['mode'] ?? null) === 'photo')) {
            // General photo editing is real: the current pipeline enhances then
            // forwards the original prompt to its configured revision provider.
            $preset = 'listing-ready';
        }
        if (! $preset) {
            return $base + ['reason' => 'missing_intent', 'question' => 'Describe the photo edit or video you want to make.'];
        }
        $video = in_array($preset, WorkspaceCatalog::VIDEO_PRESETS, true);
        $config = array_replace($this->catalog->defaults($preset), ['prompt' => $prompt]);
        if ($preset === 'virtual-staging') {
            $rooms = ['living' => 'living(?: room)?', 'bed' => 'bedroom', 'kitchen' => 'kitchen', 'dining' => 'dining(?: room)?', 'home_office' => '(?:home )?office', 'outdoor' => 'outdoor', 'kids_room' => 'kids?[ -]room'];
            $selectedRooms = array_keys(array_filter($rooms, fn ($pattern) => preg_match('/\b'.$pattern.'\b/i', $prompt)));
            if (count($selectedRooms) > 1) {
                return $base + ['reason' => 'ambiguous_room', 'question' => 'Which room type should these staging options use?'];
            }
            if ($selectedRooms) {
                $config['adjustments']['roomType'] = $selectedRooms[0];
                $overrides['adjustments']['roomType'] = $selectedRooms[0];
            }
            $styles = [];
            foreach (array_keys(VirtualStagingOptions::STYLES) as $style) {
                if (preg_match('/\b'.($style === 'midcentury' ? 'mid[ -]?century' : $style).'\b/i', $prompt)) {
                    $styles[] = $style;
                }
            }
            if (in_array('midcentury', $styles, true)) {
                $styles = array_values(array_diff($styles, ['modern']));
            }
            if (count($styles) > 1) {
                return $base + ['reason' => 'ambiguous_style', 'question' => 'Which furniture style should I use?'];
            }
            if ($styles) {
                $config['adjustments']['furnitureStyle'] = $styles[0];
                $overrides['adjustments']['furnitureStyle'] = $styles[0];
            }
            if (preg_match('/\b(?:remove|replace) (?:the |existing )?furniture\b/i', $prompt)) {
                $config['adjustments']['removal'] = 'on';
                $overrides['adjustments']['removal'] = 'on';
            }
        }
        if ($video && preg_match('/\b(\d{1,3})\s*(?:-\s*)?(?:seconds?|secs?|s)\b/i', $prompt, $duration)) {
            if ((int) $duration[1] < 5 || (int) $duration[1] > 120) {
                return $base + ['reason' => 'unsupported_duration', 'question' => 'Choose a video length between 5 and 120 seconds.'];
            }
            $config['duration'] = (int) $duration[1];
            $overrides['duration'] = (int) $duration[1];
        }
        if ($video && preg_match('/\b(9:16|16:9|1:1|4:5)\b/', $prompt, $ratio)) {
            $config['ratio'] = $ratio[1];
            $overrides['ratio'] = $ratio[1];
        }
        $plan = array_replace($base, [
            'status' => 'ready', 'presetId' => $preset, 'mode' => $video ? 'video' : 'photo', 'config' => $config,
            'configOverrides' => (object) $overrides,
            'requiresPreparation' => $video, 'requiresConfirmation' => $preset === 'full-shoot',
        ]);
        if ($count === 0 || ($video && $count > 12)) {
            return array_replace($plan, ['status' => 'needs_input', 'reason' => 'media_count', 'question' => $count === 0 ? 'Choose at least one photo to continue.' : 'Choose up to 12 photos for one video.']);
        }
        if ($video) {
            $hasFinishingControls = class_exists(WorkspaceVideoOptions::class);
            if (preg_match('/\b(voiceover|voice-over|4k|8k)\b/i', $prompt)
                || (! $hasFinishingControls && preg_match('/\b(sound|audio|music|720p)\b/i', $prompt))) {
                return array_replace($plan, ['status' => 'needs_input', 'reason' => 'unsupported_video_control', 'question' => 'That audio or output-size option is not supported. Choose an available video option to continue.']);
            }
            if ($hasFinishingControls) {
                if (preg_match('/\b(720p|1080p)\b/i', $prompt, $resolution)) {
                    $plan['config']['resolution'] = strtolower($resolution[1]);
                    $plan['configOverrides']->resolution = strtolower($resolution[1]);
                }
                if (preg_match('/\b(?:no|without|mute|remove)\s+(?:the\s+)?(?:sound|audio|music)\b|\b(?:sound|audio)\s+off\b/i', $prompt)) {
                    $plan['config']['audio']['original'] = false;
                    $plan['config']['audio']['musicId'] = null;
                    $muteAll = preg_match('/\b(?:no|without|mute|remove)\s+(?:the\s+)?(?:sound|audio)\b|\b(?:sound|audio)\s+off\b/i', $prompt);
                    $plan['configOverrides']->audio = (object) ($muteAll ? ['original' => false, 'musicId' => null] : ['musicId' => null]);
                } elseif (preg_match('/\bmusic\b/i', $prompt)) {
                    return array_replace($plan, ['status' => 'needs_input', 'reason' => 'music_selection', 'question' => 'Choose or upload a music track for this workspace.']);
                } elseif (preg_match('/\b(sound|audio)\b/i', $prompt)) {
                    $plan['config']['audio']['original'] = true;
                    $plan['configOverrides']->audio = (object) ['original' => true];
                    $plan['notice'] = 'Original sound is retained only when the generated clips contain audio.';
                }
            }
        }
        $caps = $this->settings->capabilities();
        $ready = $caps['presets'][$preset];
        // Free text on Autoenhance/Fotello presets invokes the revision service.
        if (! $video && $prompt !== '' && in_array($preset, array_merge(StudioProviderSettings::AUTOENHANCE_SERVICES, ['full-shoot']), true) && $preset !== 'upscale' && ! ($caps['revision']['ready'] ?? false)) {
            $ready = $caps['revision'];
        }
        if (! ($ready['ready'] ?? false)) {
            return array_replace($plan, ['status' => 'unavailable', 'reason' => 'service_unavailable', 'question' => $ready['reason'] ?? 'This editing service is not configured.']);
        }

        return $plan;
    }

    private function effectIsNegated(string $prompt, string $pattern): bool
    {
        preg_match_all($pattern, $prompt, $effects, PREG_OFFSET_CAPTURE);
        foreach ($effects[0] as [$effect, $offset]) {
            $before = mb_substr(substr($prompt, 0, $offset), -100);
            $after = substr($prompt, $offset + strlen($effect), 35);
            if (preg_match('/\b(?:not|never|without|avoid|don\x{2019}t|don\x{0027}t|do not)\s+(?:(?:make|making|create|creating|use|using|add|adding|apply|applying|turn|turning|this|it|photo|into|a|an|the|to|do|doing|any)\s+){0,7}$/iu', $before)
                || preg_match('/^\s+(?:(?:is|was)\s+)?not\b/i', $after)) {
                return true;
            }
        }

        return false;
    }

    private function hasAlternativeEffects(string $prompt): bool
    {
        if (preg_match('/\b(?:either|instead of)\b/i', $prompt)) {
            return true;
        }
        $effects = [];
        foreach (self::PHRASES as $pattern) {
            preg_match_all($pattern, $prompt, $found, PREG_OFFSET_CAPTURE);
            array_push($effects, ...$found[0]);
        }
        usort($effects, fn ($a, $b) => $a[1] <=> $b[1]);
        for ($i = 1; $i < count($effects); $i++) {
            $start = $effects[$i - 1][1] + strlen($effects[$i - 1][0]);
            if (preg_match('/\b(?:or|versus|vs)\b/i', substr($prompt, $start, max(0, $effects[$i][1] - $start)))) {
                return true;
            }
        }

        return false;
    }
}
