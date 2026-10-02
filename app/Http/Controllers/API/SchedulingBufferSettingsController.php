<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\Scheduling\GoogleRoutesBudget;
use App\Services\Scheduling\SchedulingBufferSettings;
use App\Support\LockedWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SchedulingBufferSettingsController extends Controller
{
    public function show(SchedulingBufferSettings $settings)
    {
        return response()->json(['data' => $this->data($settings)]);
    }

    public function update(Request $request, SchedulingBufferSettings $settings)
    {
        $minutes = ['required', 'integer', 'min:15', 'max:120', 'multiple_of:5'];
        $input = $request->validate([
            'version' => ['required', 'string', 'size:64'],
            'mode' => ['required', Rule::in(['google', 'mileage', 'fixed'])],
            'fixed_minutes' => $minutes, 'minimum_minutes' => $minutes,
            'allowance_minutes' => ['required', 'integer', 'min:0', 'max:30', 'multiple_of:5'],
            'fallback' => ['required', Rule::in(['mileage', 'review'])],
            'near_minutes' => $minutes,
            'medium_minutes' => [...$minutes, 'gte:near_minutes'],
            'far_minutes' => [...$minutes, 'gte:medium_minutes'],
        ]);
        $version = $input['version'];
        unset($input['version']);
        foreach (['fixed_minutes', 'minimum_minutes', 'allowance_minutes', 'near_minutes', 'medium_minutes', 'far_minutes'] as $field) {
            $input[$field] = (int) $input[$field];
        }
        LockedWrite::run(fn () => DB::transaction(function () use ($input, $version, $settings, $request) {
            if (! hash_equals($settings->version(true), $version)) {
                throw new ConflictHttpException('Buffer settings changed. Reopen this dialog to load the latest settings.');
            }
            $before = $settings->current();
            DB::table('settings')->upsert([[
                'key' => SchedulingBufferSettings::KEY, 'value' => json_encode($input, JSON_THROW_ON_ERROR),
                'type' => 'json', 'description' => 'Scheduling travel buffer policy',
                'created_at' => now(), 'updated_at' => now(),
            ]], ['key'], ['value', 'type', 'description', 'updated_at']);
            app(AuditLogService::class)->record('scheduling.buffer_settings_updated', $request->user(), null,
                ['before' => $before, 'after' => $input]);
        }), 'scheduling-buffer-settings');

        return response()->json(['data' => $this->data($settings)]);
    }

    private function data(SchedulingBufferSettings $settings): array
    {
        return [
            'settings' => $settings->current(true), 'version' => $settings->version(),
            'google' => ['key_configured' => trim((string) config('services.google_routes.key')) !== '',
                'budget' => app(GoogleRoutesBudget::class)->status()],
        ];
    }
}
