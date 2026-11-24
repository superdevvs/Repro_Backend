<?php

namespace App\Services\Recruiting;

use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitJobPosting;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * One path for applications from the public careers pages and from the
 * website's careers form: validate, create or update the applicant in
 * Applied, store their files privately, tell recruiters.
 */
class RecruitApplicationIntake
{
    public function __construct(
        private readonly RecruitApplicantService $applicants,
        private readonly RecruitFileService $files,
    ) {}

    public static function rules(bool $needsRole): array
    {
        return [
            'role' => [$needsRole ? 'required' : 'nullable', Rule::in(RecruitApplicant::ROLES)],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:40'],
            'states' => ['nullable', 'array', 'max:60'],
            'states.*' => ['string', 'max:40'],
            'metros' => ['nullable', 'string', 'max:255'],
            'travel_radius' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'portfolio_url' => ['nullable', 'url', 'max:500'],
            'gear' => ['nullable'],
            'flies_drone' => ['nullable', 'boolean'],
            'part107_number' => ['nullable', 'string', 'max:40'],
            'part107_expiry' => ['nullable', 'date'],
            'insurance_expiry' => ['nullable', 'date'],
            'sales_experience' => ['nullable', 'string', 'max:5000'],
            'specialty' => ['nullable', Rule::in(RecruitApplicant::SPECIALTIES)],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'resume' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'license' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,heic,webp', 'max:10240'],
            'insurance' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,heic,webp', 'max:10240'],
            'samples' => ['nullable', 'array', 'max:6'],
            'samples.*' => ['file', 'mimes:jpg,jpeg,png,heic,webp,pdf', 'max:15360'],
        ];
    }

    /** @param  array<string, mixed>  $data  validated input, files included */
    public function intake(array $data, ?RecruitJobPosting $posting): RecruitApplicant
    {
        if (isset($data['gear']) && is_string($data['gear'])) {
            $data['gear'] = preg_split('/[\n,;]+/', $data['gear']) ?: [];
        }
        $applicant = $this->applicants->fromWebsite($data, $posting);

        $uploads = [];
        foreach (['resume' => 'resume', 'license' => 'license', 'insurance' => 'insurance'] as $field => $kind) {
            if (($data[$field] ?? null) instanceof UploadedFile) {
                $uploads[] = [$data[$field], $kind];
            }
        }
        foreach ((array) ($data['samples'] ?? []) as $sample) {
            if ($sample instanceof UploadedFile) {
                $uploads[] = [$sample, 'portfolio_sample'];
            }
        }
        foreach ($uploads as [$file, $kind]) {
            try {
                $this->files->store($applicant, $file, $kind, null);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $applicant;
    }
}
