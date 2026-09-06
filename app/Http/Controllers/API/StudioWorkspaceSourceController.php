<?php

namespace App\Http\Controllers\API;

use App\Models\ShootFile;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Studio\WorkspaceMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioWorkspaceSourceController extends StudioSourceController
{
    protected const STUDIO_ROLES = ['admin', 'superadmin', 'editing_manager', 'editor', 'client'];

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
            abort(422, 'This RAW image has no supported browser preview.');
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

    public function shootMedia(Request $request, string $shoot): JsonResponse
    {
        $response = parent::shootMedia($request, $shoot);
        $data = $response->getData(true);
        $authorization = app(ShootAuthorizationSupport::class);
        $files = ShootFile::with('shoot')->whereIn('id', array_column($data['data'], 'id'))->get()->keyBy('id');
        $data['data'] = array_values(array_filter($data['data'], function ($item) use ($files, $request, $authorization): bool {
            $file = $files->get($item['id']);

            return $file && $authorization->canInteractWithShootMediaFile($file->shoot, $file, $request->user())
                && ! app(\App\Services\Shoots\ShootClientReleaseAccessService::class)->isFileReleaseLocked($file->shoot, $file, $request->user());
        }));
        $data['meta']['total'] = count($data['data']);

        return response()->json($data);
    }
}
