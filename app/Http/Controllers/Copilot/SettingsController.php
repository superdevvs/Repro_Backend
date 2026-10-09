<?php

namespace App\Http\Controllers\Copilot;

use App\Http\Controllers\Controller;
use App\Services\Copilot\CopilotOAuth;
use App\Services\Copilot\CopilotSettings;
use App\Services\RolePermissionService;
use Illuminate\Http\Request;

final class SettingsController extends Controller
{
    public function show(Request $request, CopilotSettings $settings)
    {
        abort_unless(app(RolePermissionService::class)->userCan($request->user(), 'integrations', 'view'), 403);

        return response()->json(['data' => $this->data($request, $settings)]);
    }

    public function update(Request $request, CopilotSettings $settings)
    {
        abort_unless($settings->canManage($request->user()), 403, 'Only integration administrators can change Copilot controls.');
        abort_if($request->header('X-Impersonate-User-Id') || $request->attributes->get('is_impersonating'), 403, 'Exit impersonation before changing Copilot controls.');
        $input = $request->validate(['version' => 'required|string|size:64', 'enabled' => 'required|boolean',
            'allow_changes' => 'required|boolean', 'listing_url' => ['nullable', 'string', 'max:2048', function ($attribute, $value, $fail) {
                if (! $value) { return; }
                $parts = parse_url($value);
                if (! $parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'chatgpt.com'
                    || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
                    || ! preg_match('#^/(plugins|apps|connectors)/[A-Za-z0-9_-]+/?$#', $parts['path'] ?? '')) {
                    $fail('Use the HTTPS ChatGPT listing URL for Repro.');
                }
            }], 'features' => ['required', 'array:'.implode(',', array_keys(CopilotSettings::FEATURES))],
            ...array_combine(array_map(fn ($key) => 'features.'.$key, array_keys(CopilotSettings::FEATURES)),
                array_fill(0, count(CopilotSettings::FEATURES), 'required|boolean'))]);
        $version = $input['version']; unset($input['version']);
        $input['enabled'] = (bool) $input['enabled']; $input['allow_changes'] = (bool) $input['allow_changes'];
        $input['features'] = array_map(fn ($value) => (bool) $value, $input['features']);
        $input['listing_url'] = $input['listing_url'] ?? '';
        $settings->save($input, $version, $request->user());

        return response()->json(['data' => $this->data($request, $settings)]);
    }

    private function data(Request $request, CopilotSettings $service): array
    {
        $settings = $service->current();

        return ['settings' => $settings, 'version' => $service->version($settings), 'can_manage' => $service->canManage($request->user()),
            'features' => array_map(fn ($key, $details) => ['key' => $key, 'label' => $details[0], 'description' => $details[1]],
                array_keys(CopilotSettings::FEATURES), array_values(CopilotSettings::FEATURES)),
            'connection_url' => $settings['listing_url'] ?: 'https://chatgpt.com/plugins',
            'connection_mode' => $settings['listing_url'] ? 'listing' : 'setup', 'mcp_url' => app(CopilotOAuth::class)->resource()];
    }
}
