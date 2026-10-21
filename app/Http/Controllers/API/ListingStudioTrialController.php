<?php

namespace App\Http\Controllers\API;

use App\Exceptions\PublicApiException;
use App\Http\Controllers\Controller;
use App\Services\ListingStudio\ListingStudioTrialService;
use App\Services\SupportAttachmentService;
use App\Services\UploadValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ListingStudioTrialController extends Controller
{
    public function __construct(private ListingStudioTrialService $trials) {}

    public function show(Request $request): JsonResponse
    {
        $this->requireOwnAccount($request);
        return response()->json(['data' => $this->trials->status($request->user())]);
    }

    public function store(Request $request, SupportAttachmentService $attachments, UploadValidationService $validation): JsonResponse
    {
        $this->requireOwnAccount($request);
        $this->trials->requireVerified($request->user());
        $data = $request->validate([
            'request_key' => ['required', 'uuid'],
            'edit_types' => ['required', 'array', 'list', 'size:'.ListingStudioTrialService::PHOTO_COUNT],
            'edit_types.*' => ['required', Rule::in(array_keys(ListingStudioTrialService::EDIT_TYPES))],
            'notes' => ['nullable', 'string', 'max:4000'],
            'attachments' => ['required', 'array', 'list', 'size:'.ListingStudioTrialService::PHOTO_COUNT],
            'attachments.*' => ['required', 'file', 'image', 'mimetypes:'.implode(',', ListingStudioTrialService::MIME_TYPES),
                'max:'.ListingStudioTrialService::MAX_FILE_KB],
        ]);
        $hashes = [];
        foreach ($request->file('attachments') as $index => $file) {
            $image = @getimagesize($file->getRealPath());
            if (! is_array($image) || ! in_array($image['mime'] ?? '', ListingStudioTrialService::MIME_TYPES, true)
                || $validation->hasDangerousContentType($file)) {
                throw ValidationException::withMessages(['attachments.'.$index => 'Choose a JPEG, PNG or WebP photo.']);
            }
            $hash = hash_file('sha256', $file->getRealPath());
            if (in_array($hash, $hashes, true)) {
                throw ValidationException::withMessages(['attachments.'.$index => 'Choose three different photos.']);
            }
            $hashes[] = $hash;
        }
        $data['notes'] = trim($data['notes'] ?? '');
        $data['trial_request_key'] = $data['request_key'];
        // Cannot collide with a normal support request, whose key must be a UUID.
        $data['request_key'] = 'listing-studio-free-edits:'.$data['request_key'];
        $result = $attachments->submit($request, $data, fn ($prepared) => $this->trials->claim($request->user(), $prepared));

        return response()->json(['data' => $result['claim']], $result['created'] ? 201 : 200);
    }

    private function requireOwnAccount(Request $request): void
    {
        if ($request->attributes->get('is_impersonating')) {
            throw new PublicApiException('Sign in to your own client account to request this offer.', 'trial_client_required', 403);
        }
    }
}
