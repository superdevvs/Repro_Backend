<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabProject;
use App\Models\IguideLabReview;
use App\Models\IguideLabRun;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LabReviews
{
    public function add(IguideLabProject $project, IguideLabRun $run, array $input): array
    {
        abort_unless($run->project_id === $project->id, 404);
        abort_unless($run->status === 'completed', 422, 'Only completed outputs can be reviewed.');
        $checks = $input['checks'];
        ksort($checks);
        if ($input['verdict'] === 'accepted') {
            foreach (['edit', 'geometry', 'seam', 'appearance'] as $check) abort_unless($checks[$check] === 'pass', 422, 'Acceptance requires all applicable checks to pass.');
            abort_unless($checks['crossView'] === ($input['scope'] === 'cross_view' ? 'pass' : 'not_applicable'), 422, 'Acceptance must explicitly match the reviewed scope.');
        }
        $metrics = $input['metrics'] ?? null;
        abort_if(strlen(json_encode($metrics, JSON_THROW_ON_ERROR)) > 8192, 422, 'Review metrics must be at most 8 KB.');
        $payload = ['run_id' => $run->id, 'verdict' => $input['verdict'], 'checks' => $checks,
            'notes' => $input['notes'] ?? '', 'pipeline' => $input['pipeline'], 'scope' => $input['scope'],
            'batch_id' => $input['batchId'] ?? null, 'metrics' => $this->canonical($metrics)];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return LockedWrite::run(fn () => DB::transaction(function () use ($project, $input, $hash, $payload) {
            IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]);
            abort_unless($project->fresh()->active(), 401, 'This lab session is inactive.');
            $existing = IguideLabReview::where('project_id', $project->id)->where('request_id', $input['requestId'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This review request ID was already used with different content.');
                return [$existing, false];
            }
            return [IguideLabReview::create($payload + ['public_id' => (string) Str::uuid(), 'project_id' => $project->id,
                'request_id' => $input['requestId'], 'request_hash' => $hash]), true];
        }), 'iguide-lab.review');
    }

    public function dto(IguideLabReview $review): array
    {
        return ['id' => $review->public_id, 'requestId' => $review->request_id, 'verdict' => $review->verdict,
            'checks' => $review->checks, 'notes' => $review->notes, 'pipeline' => $review->pipeline, 'scope' => $review->scope,
            'batchId' => $review->batch_id, 'metrics' => $review->metrics, 'createdAt' => $review->created_at->toIso8601String()];
    }

    public function latest(IguideLabRun $run): ?array
    {
        $review = IguideLabReview::where('project_id', $run->project_id)->where('run_id', $run->id)->orderByDesc('id')->first();
        return $review ? $this->dto($review) : null;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (! array_is_list($value)) ksort($value);
        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
