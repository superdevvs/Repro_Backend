<?php

namespace App\Services\Recruiting;

use App\Models\Recruiting\RecruitJobPosting;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Job postings: one role in one region, with a public apply page at /careers/{slug}. */
class RecruitPostingService
{
    public const STATUSES = ['draft', 'open', 'closed'];

    public function create(array $data, User $actor): RecruitJobPosting
    {
        $state = RecruitCoverage::stateCode($data['state'] ?? null) ?? ($data['state'] ?? '');

        return RecruitJobPosting::create([
            'role' => $data['role'],
            'title' => $data['title'],
            'state' => $state,
            'region' => $data['region'] ?? null,
            'description' => $data['description'] ?? null,
            'slug' => $this->slug($data['title'].' '.$state),
            'status' => 'draft',
            'created_by' => $actor->id,
        ]);
    }

    public function update(RecruitJobPosting $posting, array $data): RecruitJobPosting
    {
        if (isset($data['state'])) {
            $data['state'] = RecruitCoverage::stateCode($data['state']) ?? $data['state'];
        }
        $posting->fill(collect($data)->only(['title', 'state', 'region', 'description', 'role'])->all())->save();

        return $posting;
    }

    public function setStatus(RecruitJobPosting $posting, string $status): RecruitJobPosting
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }
        if ($status === 'open' && trim((string) $posting->description) === '') {
            throw ValidationException::withMessages(['description' => 'Add a description before opening the posting.']);
        }
        $posting->forceFill([
            'status' => $status,
            'opened_at' => $status === 'open' ? ($posting->opened_at ?? now()) : $posting->opened_at,
            'closed_at' => $status === 'closed' ? now() : null,
        ])->save();

        return $posting;
    }

    private function slug(string $source): string
    {
        $base = Str::slug($source) ?: 'role';
        $slug = $base;
        $n = 2;
        while (RecruitJobPosting::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
