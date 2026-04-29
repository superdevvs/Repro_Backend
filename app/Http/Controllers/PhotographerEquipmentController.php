<?php

namespace App\Http\Controllers;

use App\Models\PhotographerEquipment;
use App\Models\PhotographerEquipmentPhoto;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PhotographerEquipmentController extends Controller
{
    public function adminIndex(Request $request)
    {
        if (!$this->equipmentTablesReady()) {
            return $this->equipmentTablesMissingResponse();
        }

        $query = PhotographerEquipment::query()
            ->with(['photographer:id,name,email,role', 'photos', 'verifier:id,name,email'])
            ->latest();

        if ($request->filled('photographer_id')) {
            $query->where('photographer_id', (int) $request->input('photographer_id'));
        }

        if ($request->filled('status') && in_array($request->input('status'), PhotographerEquipment::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhereHas('photographer', function ($photographerQuery) use ($search) {
                        $photographerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return response()->json([
            'data' => $query->get()->map(fn (PhotographerEquipment $equipment) => $this->presentEquipment($equipment)),
        ]);
    }

    public function adminStore(Request $request)
    {
        if (!$this->equipmentTablesReady()) {
            return $this->equipmentTablesMissingResponse();
        }

        $validated = $request->validate([
            'photographer_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['nullable', 'date'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['file', 'image', 'max:10240'],
        ]);

        $photographer = User::query()->findOrFail($validated['photographer_id']);
        if (!$this->isPhotographer($photographer)) {
            return response()->json(['message' => 'Equipment can only be assigned to photographers.'], 422);
        }

        $equipment = PhotographerEquipment::create([
            'photographer_id' => $photographer->id,
            'name' => $validated['name'],
            'serial_number' => $validated['serial_number'] ?? null,
            'issue_date' => $validated['issue_date'] ?? null,
            'status' => PhotographerEquipment::STATUS_PENDING,
        ]);

        $this->storePhotos(
            $equipment,
            $request->file('photos', []),
            PhotographerEquipmentPhoto::TYPE_ADMIN_REFERENCE,
            $request->user()
        );

        return response()->json([
            'message' => 'Equipment assigned successfully.',
            'data' => $this->presentEquipment($equipment->fresh(['photographer', 'photos', 'verifier'])),
        ], 201);
    }

    public function adminUpdate(Request $request, int $equipmentId)
    {
        if (!$this->equipmentTablesReady()) {
            return $this->equipmentTablesMissingResponse();
        }

        $equipment = PhotographerEquipment::query()->findOrFail($equipmentId);

        $validated = $request->validate([
            'photographer_id' => ['sometimes', 'integer', 'exists:users,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(PhotographerEquipment::STATUSES)],
        ]);

        if (array_key_exists('photographer_id', $validated)) {
            $photographer = User::query()->findOrFail($validated['photographer_id']);
            if (!$this->isPhotographer($photographer)) {
                return response()->json(['message' => 'Equipment can only be assigned to photographers.'], 422);
            }
        }

        $equipment->fill($validated)->save();

        return response()->json([
            'message' => 'Equipment updated successfully.',
            'data' => $this->presentEquipment($equipment->fresh(['photographer', 'photos', 'verifier'])),
        ]);
    }

    public function adminDestroy(int $equipmentId)
    {
        if (!$this->equipmentTablesReady()) {
            return $this->equipmentTablesMissingResponse();
        }

        $equipment = PhotographerEquipment::query()->findOrFail($equipmentId);
        $equipment->load('photos');
        foreach ($equipment->photos as $photo) {
            $this->deleteStoredPhoto($photo);
        }

        $equipment->delete();

        return response()->json(['message' => 'Equipment deleted successfully.']);
    }

    public function adminUploadPhotos(Request $request, int $equipmentId)
    {
        if (!$this->equipmentTablesReady()) {
            return $this->equipmentTablesMissingResponse();
        }

        $equipment = PhotographerEquipment::query()->findOrFail($equipmentId);

        $request->validate([
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['file', 'image', 'max:10240'],
        ]);

        $this->storePhotos(
            $equipment,
            $request->file('photos', []),
            PhotographerEquipmentPhoto::TYPE_ADMIN_REFERENCE,
            $request->user()
        );

        return response()->json([
            'message' => 'Reference photos uploaded successfully.',
            'data' => $this->presentEquipment($equipment->fresh(['photographer', 'photos', 'verifier'])),
        ]);
    }

    public function approve(Request $request, int $equipmentId)
    {
        if (!$this->equipmentTablesReady()) {
            return $this->equipmentTablesMissingResponse();
        }

        $equipment = PhotographerEquipment::query()->findOrFail($equipmentId);

        $equipment->forceFill([
            'status' => PhotographerEquipment::STATUS_VERIFIED,
            'verified_at' => now(),
            'verified_by' => $request->user()->id,
            'rejected_at' => null,
            'rejection_reason' => null,
        ])->save();

        return response()->json([
            'message' => 'Equipment verified successfully.',
            'data' => $this->presentEquipment($equipment->fresh(['photographer', 'photos', 'verifier'])),
        ]);
    }

    public function reject(Request $request, int $equipmentId)
    {
        if (!$this->equipmentTablesReady()) {
            return $this->equipmentTablesMissingResponse();
        }

        $equipment = PhotographerEquipment::query()->findOrFail($equipmentId);

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $equipment->forceFill([
            'status' => PhotographerEquipment::STATUS_REJECTED,
            'rejected_at' => now(),
            'verified_at' => null,
            'verified_by' => null,
            'rejection_reason' => $validated['rejection_reason'] ?? null,
        ])->save();

        return response()->json([
            'message' => 'Equipment verification rejected.',
            'data' => $this->presentEquipment($equipment->fresh(['photographer', 'photos', 'verifier'])),
        ]);
    }

    public function sendVerificationEmail(Request $request, int $equipmentId, MailService $mailService)
    {
        if (!$this->equipmentTablesReady()) {
            return $this->equipmentTablesMissingResponse();
        }

        $equipment = PhotographerEquipment::query()->findOrFail($equipmentId);
        $equipment->load('photographer');
        $photographer = $equipment->photographer;

        if (!$photographer) {
            return response()->json(['message' => 'Assigned photographer could not be found.'], 404);
        }

        $pendingCount = PhotographerEquipment::query()
            ->where('photographer_id', $photographer->id)
            ->whereIn('status', [PhotographerEquipment::STATUS_PENDING, PhotographerEquipment::STATUS_REJECTED])
            ->count();

        if (!$mailService->sendPhotographerEquipmentVerificationEmail($photographer, $pendingCount)) {
            return response()->json(['message' => 'Unable to send verification email.'], 500);
        }

        $equipment->forceFill(['verification_requested_at' => now()])->save();

        return response()->json([
            'message' => 'Verification email sent.',
            'data' => $this->presentEquipment($equipment->fresh(['photographer', 'photos', 'verifier'])),
        ]);
    }

    public function photographerIndex(Request $request)
    {
        if (!$this->equipmentTablesReady()) {
            return $this->equipmentTablesMissingResponse();
        }

        $equipments = PhotographerEquipment::query()
            ->with(['photos', 'verifier:id,name,email'])
            ->where('photographer_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'data' => $equipments->map(fn (PhotographerEquipment $equipment) => $this->presentEquipment($equipment)),
        ]);
    }

    public function photographerUploadVerificationPhotos(Request $request, int $equipmentId)
    {
        if (!$this->equipmentTablesReady()) {
            return $this->equipmentTablesMissingResponse();
        }

        $equipment = PhotographerEquipment::query()->findOrFail($equipmentId);

        if ((int) $equipment->photographer_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['file', 'image', 'max:10240'],
        ]);

        $this->storePhotos(
            $equipment,
            $request->file('photos', []),
            PhotographerEquipmentPhoto::TYPE_PHOTOGRAPHER_VERIFICATION,
            $request->user()
        );

        $equipment->forceFill([
            'status' => PhotographerEquipment::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'verified_at' => null,
            'verified_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ])->save();

        return response()->json([
            'message' => 'Verification photos submitted.',
            'data' => $this->presentEquipment($equipment->fresh(['photographer', 'photos', 'verifier'])),
        ]);
    }

    public function showPhoto(Request $request, int $equipmentId, int $photoId)
    {
        if (!$this->equipmentTablesReady()) {
            return $this->equipmentTablesMissingResponse();
        }

        $equipment = PhotographerEquipment::query()->findOrFail($equipmentId);
        $photo = PhotographerEquipmentPhoto::query()->findOrFail($photoId);

        if ((int) $photo->equipment_id !== (int) $equipment->id) {
            abort(404);
        }

        if (!$this->canViewEquipment($request->user(), $equipment)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!Storage::disk($photo->disk)->exists($photo->path)) {
            abort(404);
        }

        return response()->file(Storage::disk($photo->disk)->path($photo->path), [
            'Content-Type' => $photo->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . addslashes($photo->original_name ?: basename($photo->path)) . '"',
        ]);
    }

    /**
     * @param array<int, UploadedFile>|UploadedFile|null $files
     */
    public function storeAdminReferencePhotosFromAccountCreation(PhotographerEquipment $equipment, array|UploadedFile|null $files, User $uploadedBy): void
    {
        $this->storePhotos($equipment, $files, PhotographerEquipmentPhoto::TYPE_ADMIN_REFERENCE, $uploadedBy);
    }

    private function presentEquipment(PhotographerEquipment $equipment): array
    {
        $equipment->loadMissing(['photographer:id,name,email,role', 'photos', 'verifier:id,name,email']);

        return [
            'id' => $equipment->id,
            'photographer_id' => $equipment->photographer_id,
            'photographer' => $equipment->photographer ? [
                'id' => $equipment->photographer->id,
                'name' => $equipment->photographer->name,
                'email' => $equipment->photographer->email,
            ] : null,
            'name' => $equipment->name,
            'serial_number' => $equipment->serial_number,
            'issue_date' => optional($equipment->issue_date)?->toDateString(),
            'status' => $equipment->status,
            'verification_requested_at' => optional($equipment->verification_requested_at)?->toIso8601String(),
            'submitted_at' => optional($equipment->submitted_at)?->toIso8601String(),
            'verified_at' => optional($equipment->verified_at)?->toIso8601String(),
            'rejected_at' => optional($equipment->rejected_at)?->toIso8601String(),
            'verified_by' => $equipment->verifier ? [
                'id' => $equipment->verifier->id,
                'name' => $equipment->verifier->name,
                'email' => $equipment->verifier->email,
            ] : null,
            'rejection_reason' => $equipment->rejection_reason,
            'photos' => $equipment->photos
                ->sortByDesc('created_at')
                ->values()
                ->map(fn (PhotographerEquipmentPhoto $photo) => [
                    'id' => $photo->id,
                    'equipment_id' => $photo->equipment_id,
                    'type' => $photo->type,
                    'original_name' => $photo->original_name,
                    'mime_type' => $photo->mime_type,
                    'size' => $photo->size,
                    'created_at' => optional($photo->created_at)?->toIso8601String(),
                    'url' => "/api/photographer-equipments/{$equipment->id}/photos/{$photo->id}",
                ])->all(),
            'created_at' => optional($equipment->created_at)?->toIso8601String(),
            'updated_at' => optional($equipment->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @param array<int, UploadedFile>|UploadedFile|null $files
     */
    private function storePhotos(PhotographerEquipment $equipment, array|UploadedFile|null $files, string $type, ?User $uploadedBy): void
    {
        $files = $files instanceof UploadedFile ? [$files] : ($files ?: []);

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store("photographer-equipment/{$equipment->id}/{$type}", 'local');
            $equipment->photos()->create([
                'uploaded_by' => $uploadedBy?->id,
                'type' => $type,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }

    private function deleteStoredPhoto(PhotographerEquipmentPhoto $photo): void
    {
        if (Storage::disk($photo->disk)->exists($photo->path)) {
            Storage::disk($photo->disk)->delete($photo->path);
        }
    }

    private function canViewEquipment(User $user, PhotographerEquipment $equipment): bool
    {
        return $this->isAdmin($user) || (int) $equipment->photographer_id === (int) $user->id;
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true);
    }

    private function isPhotographer(User $user): bool
    {
        return $user->role === 'photographer' || in_array('photographer', (array) $user->secondary_roles, true);
    }

    private function equipmentTablesReady(): bool
    {
        return Schema::hasTable('photographer_equipments')
            && Schema::hasTable('photographer_equipment_photos');
    }

    private function equipmentTablesMissingResponse()
    {
        return response()->json([
            'message' => 'Photographer equipment tables are not available yet. Run backend migrations before using equipment verification.',
            'setup_required' => 'php artisan migrate',
        ], 503);
    }
}
