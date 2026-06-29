<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\DropboxWorkflowService;
use App\Services\Shoots\Actions\AssignHeroMediaAction;
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
use App\Services\Shoots\Actions\UploadExtraFilesAction;
use App\Services\Shoots\Actions\UploadShootFilesAction;
use App\Services\Shoots\Actions\VerifyShootFileAction;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootAlbumService;
use App\Services\Shoots\ShootEditorDownloadService;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootMediaInteractionService;
use App\Services\Shoots\ShootMediaReadService;
use App\Services\Shoots\ShootShareLinkReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ShootMediaController extends Controller
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
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
        protected UploadExtraFilesAction $uploadExtraFilesAction,
        protected UploadAlbumMediaAction $uploadAlbumMediaAction,
        protected DownloadShootMediaZipAction $downloadShootMediaZipAction,
        protected GenerateShootShareLinkAction $generateShootShareLinkAction,
        protected RevokeShootShareLinkAction $revokeShootShareLinkAction
    ) {
    }

    public function uploadFiles(Request $request, $shootId)
    {
        $shoot = Shoot::findOrFail($shootId);
        $result = $this->uploadShootFilesAction->execute($request, $shoot, auth()->user());

        return response()->json($result['payload'], $result['status']);
    }

    public function finalizeRawUpload(Request $request, $shootId)
    {
        $shoot = Shoot::findOrFail($shootId);
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error_type' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        $allowedRoles = ['admin', 'superadmin', 'editing_manager', 'photographer'];
        if (!in_array($user->role, $allowedRoles, true)) {
            return response()->json([
                'error_type' => 'forbidden',
                'message' => 'You do not have permission to submit raw uploads for this shoot.',
            ], 403);
        }

        // Photographer may only submit their assigned shoot or service item; admin-like roles may submit any.
        if (
            $user->role === 'photographer'
            && (int) $shoot->photographer_id !== (int) $user->id
            && !$shoot->serviceItems()->where('photographer_id', $user->id)->exists()
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

        if (!$user) {
            return response()->json([
                'error_type' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        $allowedRoles = ['admin', 'superadmin', 'editing_manager', 'editor'];
        if (!in_array($user->role, $allowedRoles, true)) {
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

        if (!$user) {
            return response()->json([
                'error_type' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        $allowedRoles = ['admin', 'superadmin', 'editing_manager'];
        if (!in_array($user->role, $allowedRoles, true)) {
            return response()->json([
                'error_type' => 'forbidden',
                'message' => 'You do not have permission to approve edits for this shoot.',
            ], 403);
        }

        $result = $action->execute($shoot, $user);

        return response()->json($result['payload'], $result['status']);
    }

    public function moveFileToCompleted(Request $request, $shootId, $fileId)
    {
        $shoot = Shoot::findOrFail($shootId);
        $file = ShootFile::where('shoot_id', $shootId)->findOrFail($fileId);

        if (!$file->canMoveToCompleted()) {
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
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function verifyFile(Request $request, $shootId, $fileId)
    {
        $shoot = Shoot::findOrFail($shootId);
        $file = ShootFile::where('shoot_id', $shootId)->findOrFail($fileId);

        if (!$file->canVerify()) {
            return response()->json([
                'message' => 'File cannot be verified at this stage',
                'current_stage' => $file->workflow_stage,
            ], 400);
        }

        if (!$this->shootAuthorizationSupport->hasRole(auth()->user(), ['admin', 'superadmin', 'editing_manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            return response()->json($this->verifyShootFileAction->execute($request, $shoot, $file, auth()->user()));
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to verify file',
                'error' => $e->getMessage(),
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

        if (!$isAdmin && !$isAssignedPhotographer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($this->toggleShootFileExtraAction->execute($request, $file));
    }

    public function favoriteMedia(Shoot $shoot, ShootFile $file)
    {
        $user = auth()->user();
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        if (!$this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->shootMediaInteractionService->toggleFavorite($file));
    }

    public function setCoverMedia(Shoot $shoot, ShootFile $file)
    {
        $user = auth()->user();
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);

        if (!$this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor', 'client'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($this->shootAuthorizationSupport->isClientUser($user) && (string) $shoot->client_id !== (string) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($this->shootAuthorizationSupport->isClientUser($user) && !$this->shootAuthorizationSupport->isClientHeroEligibleFile($file)) {
            return response()->json(['message' => 'Clients can only set hero images from visible edited photo media.'], 422);
        }

        return response()->json($this->assignHeroMediaAction->execute($shoot, $file, $user));
    }

    public function flagMedia(Request $request, Shoot $shoot, ShootFile $file)
    {
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        if (
            !$this->shootAuthorizationSupport->hasRole(auth()->user(), ['admin', 'superadmin', 'editing_manager', 'editor', 'photographer'])
            || !$this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, auth()->user())
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
        if (!$this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user)) {
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
        if (!$this->shootAuthorizationSupport->hasRole(auth()->user(), ['admin', 'superadmin', 'editing_manager', 'photographer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $this->reorderShootMediaAction->execute($request, $shoot);

            return response()->json(['message' => 'Media order updated successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to reorder media', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to update media order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteMedia(Shoot $shoot, ShootFile $file)
    {
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        if (
            !$this->shootAuthorizationSupport->hasRole(auth()->user(), ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor'])
            || !$this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, auth()->user())
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (
            $this->shootAuthorizationSupport->hasRole(auth()->user(), ['editor'])
            && !$this->canEditorDeleteEditedMediaBeforeSubmit($shoot, [$file])
        ) {
            return response()->json(['message' => 'Editors can only delete edited uploads before submitting for review.'], 403);
        }

        try {
            return response()->json($this->deleteShootMediaAction->execute($shoot, $file));
        } catch (\Exception $e) {
            Log::error('Failed to delete file', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Failed to delete file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadMedia(Request $request, Shoot $shoot, ShootFile $file)
    {
        $user = auth()->user();
        $this->shootAuthorizationSupport->ensureFileBelongsToShoot($shoot, $file);
        if (!$this->shootAuthorizationSupport->canDownloadShootMediaFile($shoot, $file, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($file->isBlockedFromDelivery()) {
            return $this->infectedFileResponse();
        }
        if ($this->shootClientReleaseAccessService->isFileReleaseLocked($shoot, $file, $user)) {
            return $this->shootClientReleaseAccessService->downloadLockedResponse();
        }

        $acceptHeader = $request->headers->get('Accept', '');
        if (
            str_contains($acceptHeader, 'application/json')
            && !str_contains($acceptHeader, 'application/octet-stream')
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
        if (!$this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user)) {
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
        if ($files->contains(fn (ShootFile $file) => !$this->shootAuthorizationSupport->canDownloadShootMediaFile($shoot, $file, $request->user()))) {
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
            !$this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor'])
            || $files->contains(fn (ShootFile $file) => !$this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user))
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (
            $this->shootAuthorizationSupport->hasRole($user, ['editor'])
            && !$this->canEditorDeleteEditedMediaBeforeSubmit($shoot, $files)
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
        if (!$this->shootAuthorizationSupport->canAccessShootMedia($shoot, $request->user())) {
            if (!$this->shootAuthorizationSupport->canViewShootDetails($shoot, $request->user())) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return response()->json([
                'data' => [],
                'count' => 0,
            ]);
        }

        return response()->json($this->shootMediaReadService->getFilesPayload($shoot, $request));
    }

    public function listMedia($id, Request $request)
    {
        $shoot = Shoot::findOrFail($id);
        if (!$this->shootAuthorizationSupport->canAccessShootMedia($shoot, $request->user())) {
            if (!$this->shootAuthorizationSupport->canViewShootDetails($shoot, $request->user())) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return response()->json([
                'data' => [],
                'counts' => $this->mediaCounts($shoot),
            ]);
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

        return response()->json($this->uploadExtraFilesAction->execute($request, $shoot, auth()->user()));
    }

    public function createAlbum(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if ($user->role === 'photographer' && !$this->shootAuthorizationSupport->isPhotographerAssignedToShoot($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (!$this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'source' => 'required|in:dropbox,local',
            'folder_path' => 'nullable|string|max:500',
            'photographer_id' => 'nullable|exists:users,id',
            'shoot_service_id' => 'nullable|integer|exists:shoot_service,id',
        ]);

        if (!empty($validated['shoot_service_id']) && !$shoot->serviceItems()->whereKey($validated['shoot_service_id'])->exists()) {
            return response()->json(['message' => 'Selected service item does not belong to this shoot'], 422);
        }
        if (
            $user->role === 'photographer'
            && !empty($validated['shoot_service_id'])
            && !$this->shootAuthorizationSupport->canPhotographerAccessServiceItem($shoot, (int) $validated['shoot_service_id'], $user)
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
        if (!$this->shootAuthorizationSupport->canAccessShootMedia($shoot, $request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'data' => $this->shootAlbumService->listAlbums($shoot, $request->user()),
        ]);
    }

    public function uploadMedia(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if ($user->role === 'photographer' && !$this->shootAuthorizationSupport->isPhotographerAssignedToShoot($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (!$this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $result = $this->uploadAlbumMediaAction->execute($request, $shoot, $user);

        return response()->json($result['payload'], $result['status']);
    }

    public function archiveShoot($id, Request $request)
    {
        $shoot = Shoot::findOrFail($id);
        $user = auth()->user();

        if (!$this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $success = $this->dropboxService->archiveShoot($shoot, $user->id);
        if ($success) {
            return response()->json([
                'message' => 'Shoot archived successfully',
                'archive_folder' => $shoot->dropbox_archive_folder,
            ]);
        }

        return response()->json(['error' => 'Failed to archive shoot'], 500);
    }

    public function editorDownloadRaw(Request $request, Shoot $shoot)
    {
        $user = $request->user();
        if (!$this->shootAuthorizationSupport->hasRole($user, ['editor', 'admin', 'superadmin', 'editing_manager'])) {
            return $this->withCors(
                response()->json(['error' => 'You are not authorized to download raw files via this endpoint'], 403),
                $request,
            );
        }
        if (!$this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)) {
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
        if (!$this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)) {
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
                response()->json(['error' => $e->getMessage()], 404),
                $request,
            );
        } catch (\Exception $e) {
            Log::error('Failed to generate share link', [
                'error' => $e->getMessage(),
                'shoot_id' => $shoot->id,
            ]);

            return $this->withCors(
                response()->json(['error' => 'Failed to generate share link: ' . $e->getMessage()], 500),
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
        if (!$this->shootAuthorizationSupport->canAccessShootMedia($shoot, $request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'data' => $this->shootShareLinkReadService->listLinks($shoot),
        ]);
    }

    public function revokeShareLink(Request $request, Shoot $shoot, $linkId)
    {
        $user = $request->user();
        if (!$this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)) {
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
        $request->validate([
            'file_ids' => 'required|array|min:1',
            'file_ids.*' => 'integer',
        ]);

        if (!$this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor', 'client'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($this->shootAuthorizationSupport->isClientUser($user) && (string) $shoot->client_id !== (string) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $fileIds = $request->input('file_ids');
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

        if (!$this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
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
            !$this->shootAuthorizationSupport->hasRole($user, ['admin', 'superadmin', 'editing_manager', 'photographer', 'editor'])
            || !$this->shootAuthorizationSupport->canAccessShootMedia($shoot, $user)
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $files = $shoot->files()->whereIn('id', $request->input('file_ids'))->get();
        if ($files->contains(fn (ShootFile $file) => !$this->shootAuthorizationSupport->canInteractWithShootMediaFile($shoot, $file, $user))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->shootMediaInteractionService->reclassify(
            $shoot,
            $request->input('file_ids'),
            $request->input('media_type')
        ));
    }

    private function canEditorDeleteEditedMediaBeforeSubmit(Shoot $shoot, iterable $files): bool
    {
        if ($this->isShootSubmittedForReview($shoot)) {
            return false;
        }

        foreach ($files as $file) {
            if (!$this->isEditableUploadedMedia($file) || $this->isFileSubmittedForReview($shoot, $file)) {
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
            'expected_raw_count' => $shoot->expected_raw_count,
            'expected_final_count' => $shoot->expected_final_count,
            'raw_missing_count' => $shoot->raw_missing_count,
            'edited_missing_count' => $shoot->edited_missing_count,
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
            && !$this->shootAuthorizationSupport->isRawCameraFile($file);
    }

    private function isFileSubmittedForReview(Shoot $shoot, ShootFile $file): bool
    {
        if (!$file->shoot_service_id) {
            return false;
        }

        $serviceItem = $shoot->serviceItems()
            ->whereKey($file->shoot_service_id)
            ->first();

        return (bool) ($serviceItem?->editing_completed_at);
    }
}
