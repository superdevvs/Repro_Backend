<?php

namespace App\Http\Controllers\API;

use App\Exceptions\PublicApiException;
use App\Http\Controllers\Controller;
use App\Models\ListingStudioGuestTrial;
use App\Services\ListingStudio\ListingStudioGuestTrialService;
use App\Services\Studio\VirtualStagingOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ListingStudioGuestTrialController extends Controller
{
    public function __construct(private ListingStudioGuestTrialService $trials) {}

    private function json(array $data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data], $status)->header('Cache-Control', 'no-store');
    }

    public function capabilities(Request $request)
    {
        return $this->json($this->trials->capabilities($request->attributes->get('studio_session_hash')));
    }

    public function index(Request $request)
    {
        return $this->json($this->trials->sessionTrials($request->attributes->get('studio_session_hash')));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.config('listing-studio-trials.max_file_kb')],
            'service' => ['required', Rule::in(array_keys(ListingStudioGuestTrialService::SERVICES))],
            'roomType' => ['sometimes', 'nullable', Rule::in(array_keys(VirtualStagingOptions::ROOMS))],
            'style' => ['sometimes', 'nullable', Rule::in(array_keys(VirtualStagingOptions::STYLES))],
            'challengeToken' => ['required', 'string', 'max:2048'],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/\A[a-zA-Z0-9_-]{8,64}\z/', $key)) {
            throw new PublicApiException('A valid edit request identifier is required.', 'idempotency_key_required', 422);
        }
        $trial = $this->trials->start($request->attributes->get('studio_session_hash'), $request->attributes->get('studio_ip_hash'),
            (string) $request->header('X-Studio-IP'), $key, $request->file('image'), $data);

        return $this->json($this->trials->present($trial), 202);
    }

    public function show(Request $request, string $trial)
    {
        return $this->json($this->trials->present($this->trials->ownedGuestTrial($trial, $request->attributes->get('studio_session_hash'))));
    }

    public function preview(Request $request, string $trial)
    {
        $record = $this->trials->ownedGuestTrial($trial, $request->attributes->get('studio_session_hash'));
        if ($record->status !== 'completed' || $record->preview_path !== 'trials/'.$record->id.'/preview.jpg') {
            throw new PublicApiException('The edited preview is not ready yet.', 'trial_not_ready', 409);
        }

        return $this->privateImage($record->preview_path);
    }

    public function originalPreview(Request $request, string $trial)
    {
        $record = $this->trials->ownedGuestTrial($trial, $request->attributes->get('studio_session_hash'));

        return $this->privateImage('trials/'.$record->id.'/original-preview.jpg');
    }

    private function privateImage(string $path)
    {
        $disk = Storage::disk(ListingStudioGuestTrialService::DISK);
        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline']);
    }

    public function claimToken(Request $request, string $trial)
    {
        return $this->json($this->trials->claimToken($this->trials->ownedGuestTrial($trial, $request->attributes->get('studio_session_hash'))));
    }

    public function claim(Request $request)
    {
        if ($request->attributes->get('is_impersonating')) {
            throw new PublicApiException('Sign in to your own client account to save these edits.', 'account_ineligible', 403);
        }
        $data = $request->validate(['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);

        return $this->json($this->trials->claim($request->user(), $data['token']));
    }

    public function clientIndex(Request $request)
    {
        return $this->json($this->trials->clientTrials($request->user()));
    }

    public function download(Request $request, string $trial)
    {
        $this->trials->authorizeClient($request->user());
        $record = ListingStudioGuestTrial::whereKey($trial)->where('client_id', $request->user()->id)->firstOrFail();
        if ($record->status !== 'completed' || ! $record->output_path || ! $this->trials->outputPathValid($record, $record->output_path)) {
            throw new PublicApiException('The edited file is not ready yet.', 'trial_not_ready', 409);
        }
        $disk = Storage::disk(ListingStudioGuestTrialService::DISK);
        abort_unless($disk->exists($record->output_path), 404);

        return $disk->download($record->output_path, 'repro-'.$record->service.'-'.$record->id.'.'.pathinfo($record->output_path, PATHINFO_EXTENSION),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
