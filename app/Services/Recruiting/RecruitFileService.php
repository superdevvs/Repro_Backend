<?php

namespace App\Services\Recruiting;

use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitApplicantFile;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Applicant documents and samples on the private disk. Never public URLs:
 * every download goes through an endpoint behind crm-recruiting:documents.
 */
class RecruitFileService
{
    public const DISK = 'local';

    public const MIMES = 'pdf,doc,docx,jpg,jpeg,png,heic,webp';

    public const MAX_KB = 20480;

    public function store(RecruitApplicant $applicant, UploadedFile $file, string $kind, ?User $actor): RecruitApplicantFile
    {
        $kind = in_array($kind, RecruitApplicantFile::KINDS, true) ? $kind : 'other';
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs('recruiting/'.$applicant->id, Str::uuid()->toString().'.'.$extension, self::DISK);
        if ($path === false) {
            throw new \RuntimeException('Unable to store the applicant document.');
        }

        return RecruitApplicantFile::create($this->attributes($applicant, $file, $kind, $path, $extension, $actor));
    }

    public function storeIntakeFile(RecruitApplicant $applicant, UploadedFile $file, string $kind): RecruitApplicantFile
    {
        $checksum = hash_file('sha256', $file->getPathname());
        if ($checksum === false) {
            throw new \RuntimeException('Unable to read the applicant document.');
        }

        $identity = ['applicant_id' => $applicant->id, 'kind' => $kind, 'intake_checksum' => $checksum];
        $existing = RecruitApplicantFile::query()->where($identity)->first();
        $directory = 'recruiting/'.$applicant->id.'/intake';
        $filename = $kind.'-'.$checksum;
        $path = $directory.'/'.$filename;
        if (! Storage::disk(self::DISK)->exists($path)
            && $file->storeAs($directory, $filename, self::DISK) === false) {
            throw new \RuntimeException('Unable to store the applicant document.');
        }
        if ($existing) {
            return $existing;
        }

        return LockedWrite::run(fn () => RecruitApplicantFile::query()->firstOrCreate(
            $identity,
            $this->attributes($applicant, $file, $kind, $path, 'bin', null),
        ), 'recruit.application.file');
    }

    private function attributes(RecruitApplicant $applicant, UploadedFile $file, string $kind, string $path, string $extension, ?User $actor): array
    {
        return [
            'applicant_id' => $applicant->id,
            'kind' => $kind,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName() ?: ('file.'.$extension), 0, 255),
            'mime' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $actor?->id,
        ];
    }

    public function download(RecruitApplicantFile $file, bool $inline = false): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($file->path), 404);

        $response = Storage::disk(self::DISK)->download($file->path, $file->original_name);
        if ($inline && in_array($file->mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
            $safe = str_replace(['"', '\\'], '', $file->original_name);
            $response->headers->set('Content-Disposition', 'inline; filename="'.$safe.'"');
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
