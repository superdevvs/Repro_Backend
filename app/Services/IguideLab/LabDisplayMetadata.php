<?php

namespace App\Services\IguideLab;

use App\Models\IguideLabComposite;
use App\Models\IguideLabDisplayMetadata;
use App\Models\IguideLabProject;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Append-only display corrections; immutable artifact manifest and review evidence stay intact. */
class LabDisplayMetadata
{
    public function add(IguideLabProject $project, IguideLabComposite $record, array $input): array
    {
        abort_unless($record->project_id === $project->id, 404);
        $payload = ['composite_id' => $record->id, 'label' => $input['label'],
            'generated_detail_description' => $input['generatedDetailDescription'], 'reason' => $input['reason']];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return LockedWrite::run(fn () => DB::transaction(function () use ($project, $input, $payload, $hash) {
            IguideLabProject::whereKey($project->id)->update(['updated_at' => now()]);
            abort_unless($project->fresh()->active(), 401);
            $saved = IguideLabDisplayMetadata::where('project_id', $project->id)->where('request_id', $input['requestId'])->first();
            if ($saved) { abort_unless(hash_equals($saved->request_hash, $hash), 409); return [$saved, false]; }
            return [IguideLabDisplayMetadata::create($payload + ['public_id' => (string) Str::uuid(), 'project_id' => $project->id,
                'request_id' => $input['requestId'], 'request_hash' => $hash]), true];
        }), 'iguide-lab.display-metadata');
    }

    public function latest(IguideLabComposite $record): ?array
    {
        $entry = IguideLabDisplayMetadata::where('project_id', $record->project_id)->where('composite_id', $record->id)->latest('id')->first();
        return $entry ? $this->dto($entry) : null;
    }

    public function dto(IguideLabDisplayMetadata $entry): array
    {
        return ['id' => $entry->public_id, 'requestId' => $entry->request_id, 'label' => $entry->label,
            'generatedDetailDescription' => $entry->generated_detail_description, 'reason' => $entry->reason,
            'createdAt' => $entry->created_at->toIso8601String()];
    }
}
