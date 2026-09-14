<?php

namespace App\Http\Controllers\API;

use App\Models\ShootFile;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Studio\WorkspaceMediaService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioWorkspaceSourceController extends StudioSourceController
{
    protected const STUDIO_ROLES = ['admin', 'superadmin', 'editing_manager', 'editor', 'client'];

    protected const BROWSE_RECENT_SHOOTS = true;

    protected function scopeShootQuery(Builder $query, Authenticatable $user): Builder
    {
        return app(ShootAuthorizationSupport::class)->scopeAccessibleShootMedia($query, $user);
    }

    public function upload(Request $request): JsonResponse
    {
        $response = parent::upload($request);
        $data = $response->getData(true);
        $data['data']['accepted'] = array_map([WorkspaceMediaService::class, 'withUploadPreview'], $data['data']['accepted'] ?? []);
        $response->setData($data);

        return $response;
    }

    public function uploadPreview(Request $request, WorkspaceMediaService $media): \Illuminate\Http\Response
    {
        $this->authorizeStudioAction($request->user(), 'view');
        $data = $request->validate(['mediaRef' => ['required', 'string', 'max:1024']]);
        try {
            $bytes = $media->uploadedPreview($data['mediaRef'], $request->user(), $this->scopeTeamId($request->user()));
        } catch (\RuntimeException $exception) {
            if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $exception;
            }
            report($exception);
            throw new \App\Exceptions\PublicApiException('This RAW image has no supported browser preview.', 'raw_preview_unavailable', 422, previous: $exception);
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate(['destination' => ['required', 'string', 'max:40'], 'recordType' => ['required', 'in:shoot'], 'recordId' => ['required', 'string', 'regex:/^[1-9][0-9]*$/']]);
        $request->merge(['workflow' => 'photo-enhancement']);
        $response = $this->shootMedia($request, $data['recordId'])->getData(true);
        $shoot = $response['meta']['shoot'];

        return response()->json(['success' => true, 'data' => ['destination' => $data['destination'], 'record' => ['recordType' => 'shoot', 'id' => (string) $shoot['id'], 'name' => $shoot['label'], 'address' => $shoot['address'], 'updatedAt' => $shoot['updatedAt']]]]);
    }

    public function filePreview(Request $request, ShootFile $file, WorkspaceMediaService $media): \Illuminate\Http\Response
    {
        $this->authorizeStudioAction($request->user(), 'view');
        $bytes = $media->filePreview($file->id, $request->user(), $this->scopeTeamId($request->user()));
        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function hdr(Request $request, \App\Services\Studio\WorkspaceHdrService $hdr): JsonResponse
    {
        $this->authorizeStudioAction($request->user(), 'view');
        $input = $request->validate(['fileIds' => ['required', 'array', 'min:2', 'max:7'], 'fileIds.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $media = $hdr->describe($input['fileIds'], $request->user(), $this->scopeTeamId($request->user()));
        $status = $request->isMethod('post') ? $hdr->start($media, $request->user(), $this->scopeTeamId($request->user())) : $hdr->status($media);
        return response()->json(['success' => true, 'data' => $status], $status['status'] === 'processing' ? 202 : 200);
    }

    public function hdrPreview(Request $request, \App\Services\Studio\WorkspaceHdrService $hdr): \Illuminate\Http\Response
    {
        $this->authorizeStudioAction($request->user(), 'view');
        $input = $request->validate(['fileIds' => ['required', 'array', 'min:2', 'max:7'], 'fileIds.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $media = $hdr->describe($input['fileIds'], $request->user(), $this->scopeTeamId($request->user()));
        abort_unless($hdr->status($media)['status'] === 'ready', 404, 'The merged HDR image is not ready.');
        return response(\Illuminate\Support\Facades\Storage::disk('local')->get($hdr->path($media)), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function shootMedia(Request $request, string $shoot): JsonResponse
    {
        $response = parent::shootMedia($request, $shoot);
        $data = $response->getData(true);
        $authorization = app(ShootAuthorizationSupport::class);
        $files = ShootFile::with(['shoot', 'serviceItem.service', 'serviceItem.shoot', 'serviceItem.photographer'])->whereIn('id', array_column($data['data'], 'id'))->get()->keyBy('id');
        $data['data'] = array_values(array_filter($data['data'], function ($item) use ($files, $request, $authorization): bool {
            $file = $files->get($item['id']);

            return $file && $authorization->canInteractWithShootMediaFile($file->shoot, $file, $request->user())
                && ! app(\App\Services\Shoots\ShootClientReleaseAccessService::class)->isFileReleaseLocked($file->shoot, $file, $request->user());
        }));
        $data['data'] = array_map(function ($item) use ($files): array {
            $file = $files->get($item['id']);
            if ($item['mediaType'] === 'raw') {
                $item['previewUrl'] = $item['thumbnailUrl'] = url("/api/studio/workspaces/sources/files/{$file->id}/preview");
            }
            $mode = $file->serviceItem ? app(\App\Services\Shoots\BracketModeResolver::class)->effectiveBracketMode($file->serviceItem) : ($file->shoot->bracket_mode ?: null);
            return array_merge($item, ['shootServiceId' => $file->shoot_service_id, 'bracketGroup' => $file->bracket_group, 'sequence' => $file->sequence,
                'bracketMode' => $mode, 'stackingEnabled' => $file->serviceItem ? $mode !== null : true, 'isExtra' => $file->isExtra(),
                'captureType' => $file->media_type, 'capturedAt' => data_get($file->metadata, 'captured_at'), 'createdAt' => $file->created_at?->toIso8601String()]);
        }, $data['data']);
        $data['meta']['total'] = count($data['data']);

        return response()->json($data);
    }
}
