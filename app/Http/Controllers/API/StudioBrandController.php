<?php

namespace App\Http\Controllers\API;

use App\Models\BrandState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudioBrandController extends StudioController
{
    private const SUPPORTED_SETTINGS = [
        'logo',
        'banner',
        'watermark',
        'primary_color',
        'secondary_color',
        'font_family',
        'output_naming',
        'include_logo',
        'include_watermark',
    ];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeStudioAction($user, 'view');

        $teamId = $this->scopeTeamId($user);

        return response()->json([
            'success' => true,
            'data' => $this->brandPayload(
                BrandState::latestCommittedForTeam($teamId),
                $teamId
            ),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeStudioAction($user, 'update');

        $supportedSettings = implode(',', self::SUPPORTED_SETTINGS);
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'settings' => ['required', 'array:' . $supportedSettings],
            'settings.logo' => ['nullable', 'string', 'max:2048'],
            'settings.banner' => ['nullable', 'string', 'max:2048'],
            'settings.watermark' => ['nullable', 'string', 'max:2048'],
            'settings.primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'settings.secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'settings.font_family' => ['nullable', 'string', 'max:255'],
            'settings.output_naming' => ['nullable', 'string', 'max:255'],
            'settings.include_logo' => ['nullable', 'boolean'],
            'settings.include_watermark' => ['nullable', 'boolean'],
        ]);

        $teamId = $this->scopeTeamId($user);
        $result = DB::transaction(function () use ($teamId, $user, $validated): array {
            $brandState = BrandState::query()
                ->whereKey($teamId)
                ->lockForUpdate()
                ->first();
            $committedVersion = $brandState?->version ?? 0;

            if ($validated['version'] !== $committedVersion) {
                return ['stale' => true, 'brandState' => $brandState];
            }

            if ($brandState === null) {
                $brandState = new BrandState([
                    'team_id' => $teamId,
                    'created_by' => (int) $user->getAuthIdentifier(),
                ]);
            }

            $brandState->settings = $validated['settings'];
            $brandState->updated_by = (int) $user->getAuthIdentifier();
            $brandState->save();

            return ['stale' => false, 'brandState' => $brandState->fresh()];
        });

        if ($result['stale']) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'stale_version',
                    'message' => 'Brand state has changed. Refresh and retry with the latest version.',
                ],
                'data' => $this->brandPayload($result['brandState'], $teamId),
            ], 409);
        }

        return response()->json([
            'success' => true,
            'data' => $this->brandPayload($result['brandState'], $teamId),
        ]);
    }

    /**
     * @return array{teamId: int, settings: array<string, mixed>, version: int, updatedBy: ?int, updatedAt: ?string}
     */
    private function brandPayload(?BrandState $brandState, int $teamId): array
    {
        return [
            'teamId' => $teamId,
            'settings' => $brandState?->settings ?? [],
            'version' => $brandState?->version ?? 0,
            'updatedBy' => $brandState?->updated_by,
            'updatedAt' => $brandState?->updated_at?->toISOString(),
        ];
    }
}
