<?php

namespace App\Services\Recruiting;

use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitJobPosting;
use App\Models\Recruiting\RecruitRoster;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where we have photographers and reps, where we are thin, and who is in the
 * pipeline for each state. Feeds the coverage map (frame 12).
 */
class RecruitCoverage
{
    public const STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
        'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
        'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
        'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
        'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    public static function stateCode(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $upper = strtoupper($value);
        if (isset(self::STATES[$upper])) {
            return $upper;
        }
        $code = array_search(strtolower($value), array_map('strtolower', self::STATES), true);

        return $code === false ? null : $code;
    }

    /** @return array{states: array<int, array<string, mixed>>, totals: array<string, int>} */
    public function map(): array
    {
        $photographers = $this->photographersByState();
        $reps = $this->repsByState();
        $pipeline = $this->pipelineByState();
        $postings = RecruitJobPosting::query()->where('status', 'open')->get(['state', 'role'])
            ->groupBy(fn ($p) => self::stateCode($p->state) ?? $p->state)
            ->map(fn ($items) => $items->count());

        $codes = collect(array_keys($photographers))->merge(array_keys($reps))->merge(array_keys($pipeline))->merge($postings->keys())->filter()->unique()->sort()->values();

        $states = $codes->map(function (string $code) use ($photographers, $reps, $pipeline, $postings) {
            $p = $photographers[$code] ?? 0;

            return [
                'code' => $code,
                'name' => self::STATES[$code] ?? $code,
                'photographers' => $p,
                'reps' => $reps[$code] ?? 0,
                'in_pipeline' => $pipeline[$code]['total'] ?? 0,
                'pipeline_by_role' => ['photographer' => $pipeline[$code]['photographer'] ?? 0, 'sales_rep' => $pipeline[$code]['sales_rep'] ?? 0],
                'open_postings' => (int) ($postings[$code] ?? 0),
                'status' => $p === 0 ? 'gap' : ($p === 1 ? 'thin' : 'covered'),
            ];
        })->values()->all();

        return [
            'states' => $states,
            'totals' => [
                'covered' => collect($states)->where('status', 'covered')->count(),
                'thin' => collect($states)->where('status', 'thin')->count(),
                'gap' => collect($states)->where('status', 'gap')->count(),
                'photographers' => array_sum($photographers),
                'reps' => array_sum($reps),
                'in_pipeline' => collect($pipeline)->sum('total'),
            ],
        ];
    }

    /** Active photographers per state: service areas first, else their home state. */
    private function photographersByState(): array
    {
        $users = User::query()->photographers()->get(['id', 'state', 'account_status'])
            ->filter(fn ($u) => ($u->account_status ?? 'active') === 'active');
        $areaStates = [];
        if (Schema::hasTable('photographer_service_areas') && $users->isNotEmpty()) {
            DB::table('photographer_service_areas')
                ->join('service_areas', 'service_areas.id', '=', 'photographer_service_areas.service_area_id')
                ->where('service_areas.kind', 'state')
                ->whereIn('photographer_service_areas.user_id', $users->pluck('id'))
                ->get(['photographer_service_areas.user_id', 'service_areas.value'])
                ->each(function ($row) use (&$areaStates) {
                    if ($code = self::stateCode($row->value)) {
                        $areaStates[$row->user_id][$code] = true;
                    }
                });
        }
        $counts = [];
        foreach ($users as $user) {
            $codes = array_keys($areaStates[$user->id] ?? []);
            if ($codes === [] && ($home = self::stateCode($user->state))) {
                $codes = [$home];
            }
            foreach ($codes as $code) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** Active sales reps per state: their roster markets, else their home state. */
    private function repsByState(): array
    {
        $roster = RecruitRoster::query()->where('role', 'sales_rep')->where('active', true)->get()->keyBy('user_id');
        $counts = [];
        User::query()->where('role', 'salesRep')->get(['id', 'state', 'account_status'])
            ->filter(fn ($u) => ($u->account_status ?? 'active') === 'active')
            ->each(function (User $user) use ($roster, &$counts) {
                $codes = collect($roster[$user->id]->states ?? [])->map(fn ($s) => self::stateCode($s))->filter()->unique()->all();
                if ($codes === [] && ($home = self::stateCode($user->state))) {
                    $codes = [$home];
                }
                foreach ($codes as $code) {
                    $counts[$code] = ($counts[$code] ?? 0) + 1;
                }
            });

        return $counts;
    }

    private function pipelineByState(): array
    {
        $out = [];
        RecruitApplicant::query()->whereNotIn('stage', ['hired', RecruitApplicant::EXIT])->get(['state', 'states', 'role'])
            ->each(function (RecruitApplicant $a) use (&$out) {
                $codes = collect($a->states ?? [])->push($a->state)->map(fn ($s) => self::stateCode($s))->filter()->unique();
                foreach ($codes as $code) {
                    $out[$code]['total'] = ($out[$code]['total'] ?? 0) + 1;
                    $out[$code][$a->role] = ($out[$code][$a->role] ?? 0) + 1;
                }
            });

        return $out;
    }
}
