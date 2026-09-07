<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Services\Shoots\Actions\AssignHeroMediaAction;
use App\Services\Shoots\Actions\ChangeServiceBracketModeAction;
use App\Services\Shoots\Actions\DeleteShootMediaAction;
use App\Services\Shoots\Actions\DownloadSelectedShootFilesAction;
use App\Services\Shoots\Actions\DownloadShootMediaAction;
use App\Services\Shoots\Actions\DownloadShootMediaZipAction;
use App\Services\Shoots\Actions\FinalizeRawUploadAction;
use App\Services\Shoots\Actions\GenerateShootShareLinkAction;
use App\Services\Shoots\Actions\MoveShootFileToCompletedAction;
use App\Services\Shoots\Actions\ReorderShootMediaAction;
use App\Services\Shoots\Actions\RevokeShootShareLinkAction;
use App\Services\Shoots\Actions\ToggleShootFileExtraAction;
use App\Services\Shoots\Actions\UploadAlbumMediaAction;
use App\Services\Shoots\Actions\UploadShootFilesAction;
use App\Services\Shoots\Actions\VerifyShootFileAction;
use App\Services\Shoots\BracketModeResolver;
use App\Services\Shoots\ShootAlbumService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootEditorDownloadService;
use App\Services\Shoots\ShootMediaInteractionService;
use App\Services\Shoots\ShootMediaReadService;
use App\Services\Shoots\ShootShareLinkReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ShootMediaController extends Controller
{
    public function __construct(
        protected ShootAuthorizationSupport $shootAuthorizationSupport,
        protected ShootAlbumService $shootAlbumService,
        protected ShootClientReleaseAccessService $shootClientReleaseAccessService,
        protected ShootMediaReadService $shootMediaReadService,
        protected ShootMediaInteractionService $shootMediaInteractionService,
        protected ShootEditorDownloadService $shootEditorDownloadService,
        protected ShootShareLinkReadService $shootShareLinkReadService,
        protected UploadShootFilesAction $uploadShootFilesAction,
        protected FinalizeRawUploadAction $finalizeRawUploadAction,
        protected MoveShootFileToCompletedAction $moveShootFileToCompletedAction,
        protected VerifyShootFileAction $verifyShootFileAction,
        protected ToggleShootFileExtraAction $toggleShootFileExtraAction,
        protected AssignHeroMediaAction $assignHeroMediaAction,
        protected ReorderShootMediaAction $reorderShootMediaAction,
        protected DeleteShootMediaAction $deleteShootMediaAction,
        protected DownloadShootMediaAction $downloadShootMediaAction,
        protected DownloadSelectedShootFilesAction $downloadSelectedShootFilesAction,
        protected UploadAlbumMediaAction $uploadAlbumMediaAction,
        protected DownloadShootMediaZipAction $downloadShootMediaZipAction,
        protected GenerateShootShareLinkAction $generateShootShareLinkAction,
        protected RevokeShootShareLinkAction $revokeShootShareLinkAction
    ) {}

    public function uploadFiles(Request $request, $shootId)
    {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();
        $shootServiceId = $request->filled('shoot_service_id')
            ? (int) $request->input('shoot_service_id')
            : null;

        if (! $this->shootAuthorizationSupport->canUploadShootMedia(
            $shoot,
            $user,
            (string) $request->input('upload_type', 'raw'),
            $shootServiceId
        )) {
            return $this->uploadForbiddenResponse();
        }

        $result = $this->uploadShootFilesAction->execute($request, $shoot, $user);

        return response()->json($result['payload'], $result['status']);
    }

    public function finalizeRawUpload(Request $request, $shootId)
    {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error_type' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        $allowedRoles = ['admin', 'superadmin', 'editing_manager', 'photographer'];
        if (! in_array($user->role, $allowedRoles, true)) {
            return response()->json([
                'error_type' => 'forbidden',
                'message' => 'You do not have permission to submit raw uploads for this shoot.',
            ], 403);
        }

        // Photographer may only submit their assigned shoot or service item; admin-like roles may submit any.
        if (
            $user->role === 'photographer'
            && (int) $shoot->photographer_id !== (int) $user->id
            && ! $shoot->serviceItems()->where('photographer_id', $user->id)->exists()
        ) {
            return response()->json([
                'error_type' => 'forbidden',
                'message' => 'This shoot is not assigned to you.',
            ], 403);
        }

        $result = $this->finalizeRawUploadAction->execute($shoot, $user);

        return response()->json($result['payload'], $result['status']);
    }

    public function finalizeEditedUpload(
        Request $request,
        $shootId,
        \App\Services\Shoots\Actions\FinalizeEditedUploadAction $action
    ) {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error_type' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        $allowedRoles = ['admin', 'superadmin', 'editing_manager', 'editor'];
        if (! in_array($user->role, $allowedRoles, true)) {
            return response()->json([
                'error_type' => 'forbidden',
                'message' => 'You do not have permission to submit edited uploads for this shoot.',
            ], 403);
        }

        $result = $action->execute($shoot, $user);

        return response()->json($result['payload'], $result['status']);
    }

    public function approveEditingReview(
        Request $request,
        $shootId,
        \App\Services\Shoots\Actions\ApproveEditingReviewAction $action
    ) {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error_type' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        $allowedRoles = ['admin', 'superadmin', 'editing_manager'];
        if (! in_array($user->role, $allowedRoles, true)) {
            return response()->json([
                'error_type' => 'forbidden',
                'message' => 'You do not have permission to approve edits for this shoot.',
            ], 403);
        }

        $result = $action->execute($shoot, $user);

        return response()->json($result['payload'], $result['status']);
    }

    public function moveFileToCompleted(Request $request, Shoot $shoot, ShootFile $file)
    {
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        abort_unless($this->shootAuthorizationSupport->hasRole($request->user(), ['admin', 'superadmin', 'editing_manager', 'editor', 'photographer'])
            && $this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $request->user()), 403, 'Forbidden');

        if (! $file->canMoveToCompleted()) {
            return response()->json([
                'message' => 'File cannot be moved to completed at this stage',
                'current_stage' => $file->workflow_stage,
            ], 400);
        }

        try {
            return response()->json($this->moveShootFileToCompletedAction->execute($shoot, $file, auth()->id()));
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to move file to completed folder',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    public function verifyFile(Request $request, Shoot $shoot, ShootFile $file)
    {
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        $this->shootAuthorizationSupport->ensureRole(['admin', 'superadmin', 'editing_manager'], $request->user());

        if (! $file->canVerify()) {
            return response()->json([
                'message' => 'File cannot be verified at this stage',
                'current_stage' => $file->workflow_stage,
            ], 400);
        }

        if (! $this->shootAuthorizationSupport->hasRole(auth()->user(), ['admin', 'superadmin', 'editing_manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            return response()->json($this->verifyShootFileAction->execute($request, $shoot, $file, auth()->user()));
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to verify file',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    public function toggleFileExtra(Request $request, Shoot $shoot, ShootFile $file)
    {
        $user = auth()->user();
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);

        $isAdmin = $this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager']);
        $isAssignedPhotographer = $user
            && $this->shootAuthorizationSupport->hasRole($user, ['photographer'])
            && $this->shootAuthorizationSupport->canPhotographerAccessFile($shoot, $file, $user);

        if (! $isAdmin && ! $isAssignedPhotographer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($this->toggleShootFileExtraAction->execute($request, $file));
    }

    public function favoriteMedia(Shoot $shoot, ShootFile $file)
    {
        $user = auth()->user();
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        if (! $this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->shootMediaInteractionService->toggleFavorite($file));
    }

    public function setCoverMedia(Shoot $shoot, ShootFile $file)
    {
        $user = auth()->user();
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        $this->shootAuthorizationSupport->ensureShootAccess($shoot, $user);
        if (! $this->shootAuthorizationSupport->isClientUser($user)) {
            abort_unless($this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user), 403, 'Forbidden');
        }

        if (! $this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor', 'client'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($this->shootAuthorizationSupport->isClientUser($user) && (string) $shoot->client_id !== (string) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($this->shootAuthorizationSupport->isClientUser($user) && ! $this->shootAuthorizationSupport->isClientHeroEligibleFile($file)) {
            return response()->json(['message' => 'Clients can only set hero images from visible edited photo media.'], 422);
        }

        return response()->json($this->assignHeroMediaAction->execute($shoot, $file, $user));
    }

    public function flagMedia(Request $request, Shoot $shoot, ShootFile $file)
    {
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        if (
            ! $this->shootAuthorizationSupport->hasRole(auth()->user(), ['admin', 'superadmin', 'editing_manager', 'editor', 'photographer'])
            || ! $this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, auth()->user())
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
            'clear_flag' => 'nullable|boolean',
        ]);

        return response()->json($this->shootMediaInteractionService->flagMedia(
            $shoot,
            $file,
            $request->input('reason'),
            $request->boolean('clear_flag')
        ));
    }

    public function commentMedia(Request $request, Shoot $shoot, ShootFile $file)
    {
        $user = auth()->user();
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        if (! $this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        return response()->json($this->shootMediaInteractionService->addComment(
            $file,
            $user?->name ?? 'User',
            $request->input('comment')
        ));
    }

    public function reorderMedia(Request $request, Shoot $shoot)
    {
        $this->shootAuthorizationSupport->ensureShootAccess($shoot, $request->user());
        if (! $this->shootAuthorizationSupport->hasRole(auth()->user(), ['admin', 'superadmin', 'editing_manager', 'photographer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate(['files' => 'required|array', 'files.*.id' => 'required|integer', 'files.*.sort_order' => 'required|integer|min:0']);
        $this->ensureManageableFileIds($shoot, collect($request->input('files'))->pluck('id')->all(), $request->user());
        try {
            $this->reorderShootMediaAction->execute($request, $shoot);

            return response()->json(['message' => 'Media order updated successfully']);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'message' => 'Failed to update media order',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    public function deleteMedia(Shoot $shoot, ShootFile $file)
    {
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        if (
            ! $this->shootAuthorizationSupport->hasRole(auth()->user(), ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor'])
            || ! $this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, auth()->user())
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (
            $this->shootAuthorizationSupport->hasRole(auth()->user(), ['editor'])
            && ! $this->canEditorDeleteEditedMediaBeforeSubmit($shoot, [$file])
        ) {
            return response()->json(['message' => 'Editors can only delete edited uploads before submitting for review.'], 403);
        }

        try {
            return response()->json($this->deleteShootMediaAction->execute($shoot, $file));
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'message' => 'Failed to delete file',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    public function downloadMedia(Request $request, Shoot $shoot, ShootFile $file)
    {
        $user = auth()->user();
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        if (! $this->shootAuthorizationSupport->canDownloadShootMediaFile($shoot, $file, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($file->isBlockedFromDelivery()) {
            return $this->infectedFileResponse();
        }
        if ($this->shootClientReleaseAccessService->isFileReleaseLocked($shoot, $file, $user)) {
            return $this->shootClientReleaseAccessService->downloadLockedResponse();
        }

        // Offline iGUIDE packages live on the private local disk. Always stream
        // them through this authenticated endpoint; never exchange the request
        // for a public-storage or third-party temporary URL.
        if ($file->isIguideOfflinePackage()) {
            return $this->downloadShootMediaAction->downloadResponse($file);
        }

        $acceptHeader = $request->headers->get('Accept', '');
        if (
            str_contains($acceptHeader, 'application/json')
            && ! str_contains($acceptHeader, 'application/octet-stream')
        ) {
            $url = $this->downloadShootMediaAction->execute($file);

            return $url
                ? response()->json(['url' => $url])
                : response()->json(['message' => 'File not available'], 404);
        }

        return $this->downloadShootMediaAction->downloadResponse($file);
    }

    public function previewFile(Shoot $shoot, ShootFile $file)
    {
        $user = auth()->user();
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        if (! $this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($file->isBlockedFromDelivery()) {
            return $this->infectedFileResponse();
        }

        $needsWatermark = $this->shootClientReleaseAccessService->isFileReleaseLocked($shoot, $file, $user);

        return $this->shootMediaReadService->previewFileResponse($file, $needsWatermark);
    }

    public function bulkDownloadMedia(Request $request, Shoot $shoot)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $files = $shoot->files()->whereIn('id', $request->input('ids'))->get();
        if ($files->contains(fn (ShootFile $file) => ! $this->shootAuthorizationSupport->canDownloadShootMediaFile($shoot, $file, $request->user()))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($files->contains(fn (ShootFile $file) => $file->isBlockedFromDelivery())) {
            return $this->infectedFileResponse();
        }
        if ($files->contains(fn (ShootFile $file) => $this->shootClientReleaseAccessService->isFileReleaseLocked($shoot, $file, $request->user()))) {
            return $this->shootClientReleaseAccessService->downloadLockedResponse();
        }

        $urls = $this->shootMediaReadService->resolveBulkDownloadUrls($shoot, $request->input('ids'));

        return response()->json([
            'count' => count($urls),
            'urls' => $urls,
        ]);
    }

    public function downloadSelectedFiles(Request $request, Shoot $shoot)
    {
        return $this->downloadSelectedShootFilesAction->execute($request, $shoot, $request->user());
    }

    public function bulkDeleteMedia(Request $request, Shoot $shoot)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $files = $shoot->files()->whereIn('id', $request->input('ids'))->get();
        $user = auth()->user();
        if (
            ! $this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor'])
            || $files->contains(fn (ShootFile $file) => ! $this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user))
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (
            $this->shootAuthorizationSupport->hasRole($user, ['editor'])
            && ! $this->canEditorDeleteEditedMediaBeforeSubmit($shoot, $files)
        ) {
            return response()->json(['message' => 'Editors can only delete edited uploads before submitting for review.'], 403);
        }

        $result = $this->shootMediaInteractionService->bulkDelete(
            $shoot,
            $files
        );

        return response()->json($result['payload'], $result['status']);
    }

    public function getFiles($id, Request $request)
    {
        $shoot = Shoot::findOrFail($id);
        if (! $this->shootAuthorizationSupport->canAccessShootMedia($shoot, $request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->shootMediaReadService->getFilesPayload($shoot, $request));
    }

    public function listMedia($id, Request $request)
    {
        $shoot = Shoot::findOrFail($id);
        if (! $this->shootAuthorizationSupport->canAccessShootMedia($shoot, $request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(
            $this->shootMediaReadService->listMediaPayload(
                $shoot,
                (string) $request->query('type', 'raw'),
                $request->user()
            )
        );
    }

    public function downloadMediaZip($id, Request $request)
    {
        $shoot = Shoot::findOrFail($id);

        return $this->downloadShootMediaZipAction->execute($request, $shoot);
    }

    public function uploadExtra($id, Request $request)
    {
        $shoot = Shoot::findOrFail($id);
        $user = $request->user();
        $shootServiceId = $request->filled('shoot_service_id')
            ? (int) $request->input('shoot_service_id')
            : null;

        if (! $this->shootAuthorizationSupport->canUploadShootMedia($shoot, $user, 'raw', $shootServiceId)) {
            return $this->uploadForbiddenResponse();
        }

        // This legacy URL now uses the canonical upload contract so provenance,
        // idempotency, partial results, and serialization stay identical.
        $request->merge([
            'upload_type' => 'raw',
            'media_type' => 'extra',
            'is_extra' => true,
        ]);
        $result = $this->uploadShootFilesAction->execute($request, $shoot, $user);

        return response()->json($result['payload'], $result['status']);
    }

    public function createAlbum(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if ($user->role === 'photographer' && ! $this->shootAuthorizationSupport->isPhotographerAssignedToShoot($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (! $this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'source' => 'required|in:local',
            'folder_path' => 'nullable|string|max:500',
            'photographer_id' => 'nullable|exists:users,id',
            'shoot_service_id' => 'nullable|integer|exists:shoot_service,id',
        ]);

        if (! empty($validated['shoot_service_id']) && ! $shoot->serviceItems()->whereKey($validated['shoot_service_id'])->exists()) {
            return response()->json(['message' => 'Selected service item does not belong to this shoot'], 422);
        }
        if (
            $user->role === 'photographer'
            && ! empty($validated['shoot_service_id'])
            && ! $this->shootAuthorizationSupport->canPhotographerAccessServiceItem($shoot, (int) $validated['shoot_service_id'], $user)
        ) {
            return response()->json(['message' => 'You can only create albums for assigned service items'], 403);
        }
        if ($user->role === 'photographer' && empty($validated['shoot_service_id']) && (string) $shoot->photographer_id !== (string) $user->id) {
            return response()->json(['message' => 'Select an assigned service item for this album'], 422);
        }

        return response()->json([
            'message' => 'Album created successfully',
            'data' => $this->shootAlbumService->createAlbum($shoot, $user, $validated),
        ], 201);
    }

    public function listAlbums(Request $request, Shoot $shoot)
    {
        if (! $this->shootAuthorizationSupport->canAccessShootMedia($shoot, $request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'data' => $this->shootAlbumService->listAlbums($shoot, $request->user()),
        ]);
    }

    public function uploadMedia(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $shootServiceId = $request->filled('shoot_service_id')
            ? (int) $request->input('shoot_service_id')
            : null;
        $uploadType = (string) $request->input('type') === 'edited' ? 'edited' : 'raw';

        if (! $this->shootAuthorizationSupport->canUploadShootMedia($shoot, $user, $uploadType, $shootServiceId)) {
            return $this->uploadForbiddenResponse();
        }

        $result = $this->uploadAlbumMediaAction->execute($request, $shoot, $user);

        return response()->json($result['payload'], $result['status']);
    }

    private function uploadForbiddenResponse()
    {
        return response()->json([
            'error_type' => 'forbidden',
            'message' => 'You do not have permission to upload media for this shoot.',
            'uploaded_files' => [],
            'errors' => [[
                'error_type' => 'forbidden',
                'message' => 'You do not have permission to upload media for this shoot.',
                'retryable' => false,
            ]],
            'success_count' => 0,
            'error_count' => 1,
            'partial_success' => false,
        ], 403);
    }

    public function editorDownloadRaw(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (! $this->shootAuthorizationSupport->hasRole($user, ['editor', 'admin', 'superadmin', 'editing_manager'])) {
            return $this->withCors(
                response()->json(['error' => 'You are not authorized to download raw files via this endpoint'], 403),
                $request,
            );
        }
        if (! $this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)) {
            return $this->withCors(
                response()->json(['error' => 'You are not authorized to access raw files for this shoot'], 403),
                $request,
            );
        }

        return $this->shootEditorDownloadService->downloadRaw($request, $shoot, $user);
    }

    public function generateShareLink(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if ($user->role !== 'editor') {
            return $this->withCors(
                response()->json(['error' => 'Only editors can generate share links'], 403),
                $request,
            );
        }
        if (! $this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)) {
            return $this->withCors(
                response()->json(['error' => 'You can only generate share links for shoots assigned to you'], 403),
                $request,
            );
        }

        try {
            $payload = $this->generateShootShareLinkAction->execute($request, $shoot, $user);
            $shareLinkId = $payload['share_link_id'] ?? null;
            if ($shareLinkId) {
                $shareLink = $shoot->shareLinks()
                    ->with('creator:id,name')
                    ->find($shareLinkId);
                if ($shareLink) {
                    $payload['share_link_entry'] = $this->shootShareLinkReadService->formatLink($shareLink);
                }
            }

            return $this->withCors(
                response()->json(array_merge($payload, ['message' => 'Share link generated successfully'])),
                $request,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->withCors(
                response()->json(['error' => \App\Services\ApiErrorResponder::publicMessage($e)], 404),
                $request,
            );
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return $this->withCors(
                response()->json(['error' => 'Failed to generate share link: '.\App\Services\ApiErrorResponder::publicMessage($e)], 500),
                $request,
            );
        }
    }

    /**
     * Standard response when a file is withheld because its virus scan flagged
     * it as infected (Req 15.7). Infected files are never previewed or
     * downloaded; legacy/unscanned files remain servable.
     */
    protected function infectedFileResponse()
    {
        return response()->json([
            'error_type' => 'file_infected',
            'message' => 'This file was flagged as infected by a virus scan and cannot be previewed or downloaded.',
        ], 403);
    }

    protected function withCors(Response $response, Request $request): Response
    {
        $origin = $request->headers->get('Origin', '*');

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        return $response;
    }

    public function listShareLinks(Request $request, Shoot $shoot)
    {
        if (! $this->shootAuthorizationSupport->canAccessShootMedia($shoot, $request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'data' => $this->shootShareLinkReadService->listLinks($shoot),
        ]);
    }

    public function revokeShareLink(Request $request, Shoot $shoot, $linkId)
    {
        $user = $request->user();
        if (! $this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $link = $shoot->shareLinks()->findOrFail($linkId);

        if ($user->role === 'editor' && $link->created_by !== $user->id) {
            return response()->json(['error' => 'You can only revoke your own share links'], 403);
        }
        if ($link->is_revoked) {
            return response()->json(['error' => 'Link is already revoked'], 400);
        }

        return response()->json($this->revokeShootShareLinkAction->execute($shoot, $link, $user));
    }

    public function reorderFiles(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $this->shootAuthorizationSupport->ensureShootAccess($shoot, $user);
        $request->validate([
            'file_ids' => 'required|array|min:1',
            'file_ids.*' => 'integer',
        ]);

        if (! $this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor', 'client'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($this->shootAuthorizationSupport->isClientUser($user) && (string) $shoot->client_id !== (string) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $fileIds = $request->input('file_ids');
        if (! $this->shootAuthorizationSupport->isClientUser($user)) {
            $this->ensureManageableFileIds($shoot, $fileIds, $user);
        }
        if ($this->shootAuthorizationSupport->isClientUser($user)) {
            $manageableCount = $shoot->files()
                ->whereIn('id', $fileIds)
                ->get()
                ->filter(fn (ShootFile $file) => $this->shootAuthorizationSupport->isClientManageableEditedFile($file))
                ->count();

            if ($manageableCount !== count(array_unique($fileIds))) {
                return response()->json(['message' => 'Clients can only reorder visible edited media from their own shoot.'], 422);
            }
        }

        return response()->json($this->shootMediaInteractionService->reorderFiles($shoot, $fileIds, $user));
    }

    public function toggleFileHidden(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $request->validate([
            'file_ids' => 'required|array|min:1',
            'file_ids.*' => 'integer',
            'hidden' => 'required|boolean',
        ]);

        if (! $this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (! $this->shootAuthorizationSupport->isClientUser($user)) {
            $this->ensureManageableFileIds($shoot, $request->input('file_ids', []), $user);
        } else {
            abort_unless((string) $shoot->client_id === (string) $user->id, 403, 'Forbidden');
        }

        if ($this->shootAuthorizationSupport->isClientUser($user)) {
            $fileIds = $request->input('file_ids', []);
            $manageableCount = $shoot->files()
                ->whereIn('id', $fileIds)
                ->get()
                ->filter(fn (ShootFile $file) => $this->shootAuthorizationSupport->isClientInteractableEditedFile($file))
                ->count();

            if ($manageableCount !== count(array_unique($fileIds))) {
                return response()->json(['message' => 'Clients can only update edited media from their own shoot.'], 422);
            }
        }

        return response()->json($this->shootMediaInteractionService->toggleHidden(
            $shoot,
            $request->input('file_ids'),
            $request->boolean('hidden')
        ));
    }

    public function reclassifyFiles(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        $request->validate([
            'file_ids' => 'required|array|min:1',
            'file_ids.*' => 'integer|exists:shoot_files,id',
            'media_type' => 'required|string|in:floorplan,raw,edited,extra,virtual_staging,green_grass,twilight,drone',
        ]);

        if (
            ! $this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor'])
            || ! $this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $files = $shoot->files()->whereIn('id', $request->input('file_ids'))->get();
        if ($files->contains(fn (ShootFile $file) => ! $this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->shootMediaInteractionService->reclassify(
            $shoot,
            $request->input('file_ids'),
            $request->input('media_type')
        ));
    }

    /**
     * Record the bracket size one service on this shoot was captured at.
     *
     * Bracket size is execution state on the shoot-service row, not a shoot-wide
     * setting: the same shoot can be Exterior at 5x by one photographer and Twilight at
     * 3x by another. Until now the picker only held local React state, so a size the
     * user chose was never durable and was lost on reload.
     *
     * Changing the size after raws exist re-cuts that service's stacks, so it cannot be
     * a silent side effect. The client has to opt in with `restack=true`, which is the
     * deliberate "Change & Restack this service" action; without it a request that would
     * disturb existing frames is refused and the caller is told why. Restacking is always
     * scoped to the one service, so another photographer's work is never touched.
     */
    public function updateServiceBracketMode(Request $request, Shoot $shoot, ShootService $shootService)
    {
        $user = $request->user();

        $validated = $request->validate([
            'bracket_mode' => 'present|nullable|integer|in:3,5',
            'restack' => 'sometimes|boolean',
        ]);

        if (
            ! $this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer'])
            || ! $this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // The row must belong to this shoot. Route-model binding resolves it globally,
        // so without this a caller could address another shoot's execution row.
        if ((int) $shootService->shoot_id !== (int) $shoot->id) {
            return response()->json([
                'error_type' => 'invalid_service_item',
                'message' => 'Selected service item does not belong to this shoot.',
            ], 422);
        }

        // A photographer may only set the size for work assigned to them.
        if ($this->shootAuthorizationSupport->hasRole($user, ['photographer'])) {
            $assignedToActor = (string) $shootService->photographer_id === (string) $user->id
                || (! $shootService->photographer_id && (string) $shoot->photographer_id === (string) $user->id);

            if (! $assignedToActor) {
                return response()->json([
                    'error_type' => 'forbidden',
                    'message' => 'You can only change the bracket size for services assigned to you.',
                ], 403);
            }
        }

        $shootService->loadMissing(['service', 'shoot']);
        $brackets = app(BracketModeResolver::class);
        $restackRequested = (bool) ($validated['restack'] ?? false);

        if ($brackets->hasRawFiles($shootService) && ! $restackRequested) {
            return response()->json([
                'error_type' => 'restack_required',
                'message' => 'This service already has raw files. Changing its bracket size has to re-cut those stacks, so confirm "Change & Restack this service".',
                'shoot_service_id' => (int) $shootService->id,
                'bracket_mode' => $shootService->bracket_mode !== null ? (int) $shootService->bracket_mode : null,
                'effective_bracket_mode' => $brackets->effectiveBracketMode($shootService),
                'had_raw_files' => true,
            ], 409);
        }

        try {
            $result = app(ChangeServiceBracketModeAction::class)->execute(
                $shootService,
                $validated['bracket_mode'] !== null ? (int) $validated['bracket_mode'] : null,
                $restackRequested
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'error_type' => 'invalid_bracket_mode',
                'message' => collect($exception->errors())->flatten()->first() ?? 'Bracket size could not be changed.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json($result);
    }

    private function ensureManageableFileIds(Shoot $shoot, array $fileIds, \App\Models\User $user): void
    {
        $files = $shoot->files()->whereIn('id', $fileIds)->get();
        abort_unless($files->count() === count(array_unique($fileIds)), 404, 'File not found');
        foreach ($files as $file) {
            abort_unless($this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user), 403, 'Forbidden');
        }
    }

    private function canEditorDeleteEditedMediaBeforeSubmit(Shoot $shoot, iterable $files): bool
    {
        if ($this->isShootSubmittedForReview($shoot)) {
            return false;
        }

        foreach ($files as $file) {
            if (! $this->isEditableUploadedMedia($file) || $this->isFileSubmittedForReview($shoot, $file)) {
                return false;
            }
        }

        return true;
    }

    private function mediaCounts(Shoot $shoot): array
    {
        return [
            'raw_photo_count' => $shoot->raw_photo_count,
            'edited_photo_count' => $shoot->edited_photo_count,
            'extra_photo_count' => $shoot->extra_photo_count,
            // Derived per service item; see BracketModeResolver. The stored column
            // is legacy and was always 0 in practice.
            'expected_raw_count' => app(\App\Services\Shoots\BracketModeResolver::class)->expectedRawForShoot($shoot),
            'expected_final_count' => $shoot->expected_final_count,
            'raw_missing_count' => $shoot->raw_missing_count,
            'edited_missing_count' => $shoot->edited_missing_count,
            // Legacy shoot-wide value; per-service bracket state is on the items.
            'bracket_mode' => $shoot->bracket_mode,
        ];
    }

    private function isShootSubmittedForReview(Shoot $shoot): bool
    {
        if ($shoot->submitted_for_review_at) {
            return true;
        }

        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status ?: ''));

        return in_array($status, ['pending_review', 'ready_for_review', 'qc', 'review', Shoot::STATUS_READY], true);
    }

    private function isEditableUploadedMedia(ShootFile $file): bool
    {
        return in_array($file->workflow_stage, [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED], true)
            && ! $this->shootAuthorizationSupport->isRawCameraFile($file);
    }

    private function isFileSubmittedForReview(Shoot $shoot, ShootFile $file): bool
    {
        if (! $file->shoot_service_id) {
            return false;
        }

        $serviceItem = $shoot->serviceItems()
            ->whereKey($file->shoot_service_id)
            ->first();

        return (bool) ($serviceItem?->editing_completed_at);
    }
}
