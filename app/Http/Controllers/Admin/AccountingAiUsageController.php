<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AiUsageRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingAiUsageController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()?->role === 'superadmin', 403);
        $input = $request->validate(['start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d|after_or_equal:start']);
        $start = CarbonImmutable::parse($input['start'], 'UTC');
        $end = CarbonImmutable::parse($input['end'], 'UTC');
        abort_if($start->diffInDays($end) > 365, 422, 'Select at most one year.');
        $rows = DB::table('ai_provider_usage')->where('provider', 'openai')
            ->whereBetween('occurred_at', [$start->format('Y-m-d H:i:s'), $end->endOfDay()->format('Y-m-d H:i:s')])->get();
        $blank = ['metered_calls' => 0, 'historical_calls' => 0, 'failed_calls' => 0, 'unconfirmed_calls' => 0,
            'input_tokens' => 0, 'cached_tokens' => 0, 'output_tokens' => 0, 'unknown_token_calls' => 0,
            'estimated_cost_usd' => null, 'unpriced_calls' => 0];
        $daily = []; $features = []; $models = []; $summary = $blank;
        for ($day = $start; $day->lte($end); $day = $day->addDay()) $daily[$day->toDateString()] = ['date' => $day->toDateString()] + $blank;
        $add = static function (array &$group, object $row): void {
            $calls = (int) $row->calls;
            $group[$row->source === 'metered' ? 'metered_calls' : 'historical_calls'] += $calls;
            if ($row->status === 'failed') $group['failed_calls'] += $calls;
            if (in_array($row->status, ['pending', 'unknown'], true)) $group['unconfirmed_calls'] += $calls;
            foreach (['input_tokens', 'cached_tokens', 'output_tokens'] as $field) $group[$field] += (int) $row->$field;
            if ($row->input_tokens === null || $row->output_tokens === null) $group['unknown_token_calls'] += $calls;
            if ($row->estimated_cost_usd === null) $group['unpriced_calls'] += $calls;
            else $group['estimated_cost_usd'] = round(($group['estimated_cost_usd'] ?? 0) + (float) $row->estimated_cost_usd, 8);
        };
        foreach ($rows as $row) {
            $day = substr($row->occurred_at, 0, 10);
            $features[$row->feature] ??= ['feature' => $row->feature, 'label' => AiUsageRecorder::FEATURES[$row->feature] ?? 'Other OpenAI calls'] + $blank;
            $models[$row->model] ??= ['model' => $row->model] + $blank;
            $add($summary, $row); $add($daily[$day], $row); $add($features[$row->feature], $row); $add($models[$row->model], $row);
        }
        return response()->json(['data' => [
            'start' => $input['start'], 'end' => $input['end'], 'timezone' => 'UTC', 'currency' => 'USD',
            'summary' => $summary, 'daily' => array_values($daily), 'features' => array_values($features), 'models' => array_values($models),
            'metering_enabled' => true,
            'first_metered_call_at' => DB::table('ai_provider_usage')->where('source', 'metered')->min('occurred_at'),
            'pricing_version' => config('ai_usage.pricing_version'),
            'scope_note' => 'RePro OpenAI API calls only. Codex subscriptions and other providers are excluded.',
            'cost_note' => 'Estimated USD from recorded tokens. This is not an OpenAI invoice. Missing usage or unknown model pricing stays unavailable.',
            'history_note' => 'October 1–10 historical counts are reconstructed minimums. Their tokens and cost are unknown; zero does not establish zero historical usage.',
        ]]);
    }
}
