<?php

namespace App\Services\IguideLab;

use InvalidArgumentException;

/** Server-owned immutable recipes. An active-default change cannot change reserved work. */
class LabAnalysisRecipes
{
    public const BASELINE = 'scene-instructions-baseline-1';
    public const CANDIDATE = 'scene-instructions-physical-dependencies-1';
    public const SECTION_CONTEXT = 'scene-instructions-section-context-1';
    public const LIGHTING_REPAIR = 'scene-instructions-lighting-repair-1';
    public const LIFESTYLE_OPTICAL_CUT = 'scene-instructions-lifestyle-cut-gate-2';
    public const LIFESTYLE_CUT = 'scene-instructions-lifestyle-cut-gate-1';
    public const STAGING_CUT = 'scene-instructions-staging-cut-gate-1';
    public const STAGING_LAYOUT = 'scene-instructions-staging-layout-1';
    public const LEGACY_PROTOCOL = 'scene-plan-v1';
    public const LEGACY_VIEW_RECIPE = 'source2k-six1024-fov100/1';
    private const FILES = [
        self::BASELINE => ['baseline-1.json', '81673992581f6d86261b7fb74e4d639b271a57d867643c304d34b9cd5b7f007a'],
        self::CANDIDATE => ['physical-dependencies-1.json', '1a57bba52b4b785ee53a3b9991fab3a2169ae06a824507fce107f79f29cc6244'],
        self::SECTION_CONTEXT => ['section-context-1.json', '9bbe4e34f1aa6355eb1f661642110ce82970dadf07884b415d121a92f358d5de'],
        self::LIGHTING_REPAIR => ['lighting-repair-1.json', 'dd1cc556379d9963b0ec4fd68d0dbb8da0c4a4cd690989a0d3b865f2dea089aa'],
        self::LIFESTYLE_OPTICAL_CUT => ['lifestyle-cut-gate-2.json', '2e82468707de449a762bd70aa0f1f67031dec1c9af6ce59a341df1af2b8688fb'],
        self::LIFESTYLE_CUT => ['lifestyle-cut-gate-1.json', 'ac42379d6bf0e87a6d772aa9ce675f39e7341e1991a983d96d65e48b8542a6f6'],
        self::STAGING_CUT => ['staging-cut-gate-1.json', '50cf8dd230363a08055a532e56a88a9b4c6613fe627b502c8eaacb4e060e73b2'],
        self::STAGING_LAYOUT => ['staging-layout-1.json', 'c3166d4b86c79f1c1b6ebdcd8f0f0dda1b7d160680013cbaa9c8ec0b70a93601'],
    ];

    public function active(): array
    {
        return $this->snapshot(config('iguide-lab.analysis_instructions_version') ?? self::CANDIDATE);
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
        if ($recipe['compilerVersion'] === LabRepairAnalysisProtocol::VERSION) return LabRepairAnalysisProtocol::compile($recipe, $context, $images);
        if ($recipe['compilerVersion'] === LabLifestyleOpticalCutProtocol::VERSION) return LabLifestyleOpticalCutProtocol::compile($recipe, $context, $images);
        if ($recipe['compilerVersion'] === LabLifestyleCutProtocol::VERSION) return LabLifestyleCutProtocol::compile($recipe, $context, $images);
        if ($recipe['compilerVersion'] === LabStagingCutProtocol::VERSION) return LabStagingCutProtocol::compile($recipe, $context, $images);
        if ($recipe['compilerVersion'] === LabStagingLayoutProtocol::VERSION) return LabStagingLayoutProtocol::compile($recipe, $context, $images);
        if ($recipe['compilerVersion'] === LabSectionAnalysisProtocol::VERSION) return LabSectionAnalysisProtocol::compile($recipe, $context, $images);
        if ($recipe['compilerVersion'] !== LabAnalysisRequestV1::VERSION) throw new InvalidArgumentException('Unsupported analysis request compiler.');
        return LabAnalysisRequestV1::compile($recipe, $context, $images);
    }

    public function parse(array $snapshot, array $response, array $context): array
    {
        $recipe = $this->validate($snapshot);
        if ($recipe['compilerVersion'] === LabRepairAnalysisProtocol::VERSION) return LabRepairAnalysisProtocol::parse($response, $context);
        if ($recipe['compilerVersion'] === LabLifestyleOpticalCutProtocol::VERSION) return LabLifestyleOpticalCutProtocol::parse($response, $context);
        if ($recipe['compilerVersion'] === LabLifestyleCutProtocol::VERSION) return LabLifestyleCutProtocol::parse($response, $context);
        if ($recipe['compilerVersion'] === LabStagingCutProtocol::VERSION) return LabStagingCutProtocol::parse($response, $context);
        if ($recipe['compilerVersion'] === LabStagingLayoutProtocol::VERSION) return LabStagingLayoutProtocol::parse($response, $context);
        return $recipe['compilerVersion'] === LabSectionAnalysisProtocol::VERSION
            ? LabSectionAnalysisProtocol::parse($response, $context) : LabScenePlanProtocol::parse($response, $context);
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
