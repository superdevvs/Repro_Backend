<?php

namespace App\Services\Recruiting;

use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitJobPosting;
use App\Services\Crm\CrmNoticeService;
use App\Support\LockedWrite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        private readonly CrmNoticeService $notices,
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

    private const FILE_FIELDS = ['resume', 'license', 'insurance', 'samples'];

    /**
     * @param  array<string, mixed>  $data  validated input, files included
     *
     * @throws RecruitReferenceConflict when the reference was first used with other content
     */
    public function intake(array $data, ?RecruitJobPosting $posting): RecruitApplicant
    {
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
        $identity = $this->identity($data, $uploads);
        if (isset($data['gear']) && is_string($data['gear'])) {
            $data['gear'] = preg_split('/[\n,;]+/', $data['gear']) ?: [];
        }
        $applicant = $this->resolveApplicant($data, $posting, $identity);

        foreach ($uploads as [$file, $kind]) {
            $this->files->storeIntakeFile($applicant, $file, $kind);
        }

        return $applicant;
    }

    /**
     * What a reference stands for: the validated fields (files, empty values
     * and key order left out) and the attachment bytes in the order sent.
     *
     * @param  list<array{0: UploadedFile, 1: string}>  $uploads
     * @return array{payload_sha256: string, attachment_sha256s: string}
     */
    private function identity(array $data, array $uploads): array
    {
        $fields = $this->canonical(array_diff_key($data, array_flip(self::FILE_FIELDS)));
        $attachments = [];
        foreach ($uploads as [$file, $kind]) {
            $checksum = hash_file('sha256', $file->getPathname());
            if ($checksum === false) {
                throw new \RuntimeException('Unable to read the applicant document.');
            }
            $attachments[] = $kind.':'.$checksum;
        }

        return [
            'payload_sha256' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'attachment_sha256s' => json_encode($attachments, JSON_THROW_ON_ERROR),
        ];
    }

    /** Sorted keys, no empty values, and scalars as text, so JSON and form posts hash alike. */
    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = array_filter(array_map(fn ($item) => $this->canonical($item), $value), fn ($item) => $item !== null);
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return $value;
        }

        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => $value,
        };
    }

    /** @param  array{payload_sha256: string, attachment_sha256s: string}  $identity */
    private function resolveApplicant(array $data, ?RecruitJobPosting $posting, array $identity): RecruitApplicant
    {
        $reference = $data['external_reference'] ?? null;
        if (! $reference) {
            return $this->applicants->fromWebsite($data, $posting);
        }

        [$applicant, $matches] = LockedWrite::run(fn () => DB::transaction(function () use ($data, $posting, $reference, $identity) {
            $receipts = DB::table('recruit_application_receipts');
            $receipts->insertOrIgnore(['reference' => $reference, 'created_at' => now(), 'updated_at' => now()]);
            $receipt = $receipts->where('reference', $reference)->lockForUpdate()->first();
            if ($receipt->applicant_id) {
                // A receipt from before hashing has no identity to compare: review it.
                $matches = is_string($receipt->payload_sha256) && is_string($receipt->attachment_sha256s)
                    && hash_equals($receipt->payload_sha256, $identity['payload_sha256'])
                    && hash_equals($receipt->attachment_sha256s, $identity['attachment_sha256s']);

                return [(int) $receipt->applicant_id, $matches];
            }

            $applicant = $this->applicants->fromWebsite($data, $posting);
            $receipts->update(['applicant_id' => $applicant->id, ...$identity, 'updated_at' => now()]);

            return [$applicant, true];
        }), 'recruit.application.receipt');

        if (! $matches) {
            $this->notices->notifyRecruiters('recruit_reference_conflict',
                'A website application replay did not match the original',
                'Nothing was saved from the replay. Compare it with the website record before changing the applicant.',
                ['applicant_id' => $applicant, 'reference' => mb_substr((string) $reference, 0, 120)]);

            throw new RecruitReferenceConflict($applicant);
        }

        return $applicant instanceof RecruitApplicant ? $applicant : RecruitApplicant::query()->findOrFail($applicant);
    }
}
