<?php

namespace App\Services\Recruiting;

use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitApplicantFile;
use App\Models\User;
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

        return RecruitApplicantFile::create([
            'applicant_id' => $applicant->id,
            'kind' => $kind,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName() ?: ('file.'.$extension), 0, 255),
            'mime' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $actor?->id,
        ]);
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
