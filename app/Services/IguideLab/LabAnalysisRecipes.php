<?php

namespace App\Services\IguideLab;

use InvalidArgumentException;

/** Server-owned immutable recipes. An active-default change cannot change reserved work. */
class LabAnalysisRecipes
{
    public const BASELINE = 'scene-instructions-baseline-1';
    public const LEGACY_PROTOCOL = 'scene-plan-v1';
    public const LEGACY_VIEW_RECIPE = 'source2k-six1024-fov100/1';
    private const FILES = [self::BASELINE => ['baseline-1.json', '81673992581f6d86261b7fb74e4d639b271a57d867643c304d34b9cd5b7f007a']];

    public function active(): array
    {
        return $this->snapshot(config('iguide-lab.analysis_instructions_version', self::BASELINE));
    }

    public function snapshot(string $version): array
    {
        [$name, $fingerprint] = self::FILES[$version] ?? throw new InvalidArgumentException('Unsupported analysis instructions version.');
        $bytes = file_get_contents(resource_path('iguide-lab/analysis-recipes/'.$name));
        if ($bytes === false || ! hash_equals($fingerprint, hash('sha256', $bytes))) throw new InvalidArgumentException('Analysis recipe file changed.');
        $recipe = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        return ['recipe' => $recipe, 'recipeSha256' => self::hash($recipe),
            'instructionsSha256' => hash('sha256', $recipe['instructions']), 'schemaSha256' => self::hash($recipe['schema'])];
    }

    public function validate(array $snapshot): array
    {
        $version = $snapshot['recipe']['instructionsVersion'] ?? null;
        if (! is_string($version) || $snapshot !== $this->snapshot($version)) throw new InvalidArgumentException('Analysis recipe snapshot changed.');
        return $snapshot['recipe'];
    }

    public function legacy(array $settings): array
    {
        if (($settings['protocolVersion'] ?? null) !== self::LEGACY_PROTOCOL || ($settings['viewRecipe'] ?? null) !== self::LEGACY_VIEW_RECIPE) {
            throw new InvalidArgumentException('Legacy analysis protocol is unsupported.');
        }
        return $this->snapshot(self::BASELINE);
    }

    public function request(array $snapshot, array $context, array $images): array
    {
        $recipe = $this->validate($snapshot);
        if ($recipe['compilerVersion'] !== LabAnalysisRequestV1::VERSION) throw new InvalidArgumentException('Unsupported analysis request compiler.');
        return LabAnalysisRequestV1::compile($recipe, $context, $images);
    }

    public function provenance(?array $snapshot, ?string $mode, ?string $bodySha256): ?array
    {
        if ($snapshot === null) return null; // Historical unknown is not claimed as captured evidence.
        try { $recipe = $this->validate($snapshot); } catch (\Throwable) { return null; }
        return ['instructionsVersion' => $recipe['instructionsVersion'], 'instructionsSha256' => $snapshot['instructionsSha256'],
            'schemaSha256' => $snapshot['schemaSha256'], 'executionRecipeSha256' => $snapshot['recipeSha256'],
            'compilerVersion' => $recipe['compilerVersion'], 'captureMode' => $mode, 'providerBodySha256' => $bodySha256];
    }

    public static function hash(array $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
}
