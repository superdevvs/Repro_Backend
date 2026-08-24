<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShootFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'shoot_service_id',
        'album_id',
        'filename',
        'stored_filename',
        'path',
        'storage_path',
        'watermarked_storage_path',
        'watermarked_thumbnail_path',
        'watermarked_web_path',
        'watermarked_placeholder_path',
        'thumbnail_path',
        'grid_path',
        'web_path',
        'placeholder_path',
        'file_type',
        'mime_type',
        'media_type',
        'treatment',
        'file_size',
        'uploaded_by',
        'uploaded_at',
        'workflow_stage',
        'sort_order',
        'dropbox_path',
        'dropbox_file_id',
        'moved_to_completed_at',
        'verified_at',
        'verified_by',
        'verification_notes',
        'is_cover',
        'is_favorite',
        'is_hidden',
        'is_extra',
        'required_for_editing',
        'scan_status',
        'scan_result',
        'scanned_at',
        'bracket_group',
        'sequence',
        'flag_reason',
        'metadata',
        'ai_editing_job_id',
        'is_ai_edited',
        'ai_editing_metadata',
        'processed_at',
        'processing_failed_at',
        'processing_error',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'moved_to_completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'processed_at' => 'datetime',
        'processing_failed_at' => 'datetime',
        'scanned_at' => 'datetime',
        'is_cover' => 'boolean',
        'is_favorite' => 'boolean',
        'is_hidden' => 'boolean',
        'is_extra' => 'boolean',
        'required_for_editing' => 'boolean',
        'bracket_group' => 'integer',
        'sequence' => 'integer',
        'sort_order' => 'integer',
        'metadata' => 'array',
        'is_ai_edited' => 'boolean',
        'ai_editing_metadata' => 'array',
    ];

    // Workflow stage constants
    const STAGE_TODO = 'todo';
    const STAGE_COMPLETED = 'completed';
    const STAGE_VERIFIED = 'verified';
    const STAGE_ARCHIVED = 'archived';
    const STAGE_FLAGGED = 'flagged';

    /**
     * Post-capture treatments requested on one individual frame.
     *
     * Held in `treatment`, never in `media_type`. A treated frame keeps its capture
     * identity (`raw`) and its booked-service ownership (`shoot_service_id`), so
     * bracket stacking, the Photos tab and delivery all continue to see it.
     */
    const TREATMENT_VIRTUAL_STAGING = 'virtual_staging';
    const TREATMENT_GREEN_GRASS = 'green_grass';
    const TREATMENT_TWILIGHT = 'twilight';

    /** The only treatments an upload may request. */
    const TREATMENTS = [
        self::TREATMENT_VIRTUAL_STAGING,
        self::TREATMENT_GREEN_GRASS,
        self::TREATMENT_TWILIGHT,
    ];

    /**
     * Normalise an incoming treatment request, or null when it is not one we accept.
     *
     * Floor plan and drone are deliberately absent: those are capture/service
     * classifications owned by the booked service group, not per-file treatments.
     */
    public static function normalizeTreatment(mixed $value): ?string
    {
        $candidate = strtolower(trim((string) ($value ?? '')));

        return in_array($candidate, self::TREATMENTS, true) ? $candidate : null;
    }

    // Virus-scan state machine (Req 14/15). Files quarantine on upload and are only
    // released to downstream processing once scanned clean.
    const SCAN_STATUS_QUARANTINED = 'quarantined';
    const SCAN_STATUS_CLEAN = 'clean';
    const SCAN_STATUS_INFECTED = 'infected';
    const SCAN_STATUS_FAILED = 'failed';

    /**
     * Whether this file has cleared Quarantine and may be handed to downstream
     * processing jobs (ProcessImageJob / UploadShootMediaToDropboxJob).
     *
     * Req 14.3 / 15.1 / 15.4: only a file with a recorded clean verdict is
     * released for downstream processing; quarantined, infected, and failed
     * files are withheld. A null scan_status is treated as a legacy file that
     * predates the scanning feature and is allowed through so existing media
     * keeps processing (documented legacy fallback).
     */
    public function isClearedForProcessing(): bool
    {
        $status = $this->scan_status;

        return $status === null || $status === self::SCAN_STATUS_CLEAN;
    }

    /**
     * Whether this file must be blocked from preview and download.
     *
     * QA #14 (hardened): a file is served only once it has a recorded CLEAN
     * verdict. Infected, quarantined (not yet scanned), and failed files are all
     * withheld from preview/download/zip — fail-closed so an upload that returned
     * before scanning, or that could not be scanned, is never delivered. Legacy
     * files (null status) predate the scanning feature and remain servable so
     * delivery of pre-existing media is not broken.
     */
    public function isBlockedFromDelivery(): bool
    {
        if ($this->scan_status === null) {
            return false;
        }

        return $this->scan_status !== self::SCAN_STATUS_CLEAN;
    }

    public function isExtra(): bool
    {
        if (array_key_exists('is_extra', $this->attributes)) {
            return (bool) $this->attributes['is_extra'];
        }

        $mediaType = strtolower((string) ($this->media_type ?? ''));
        $path = strtolower((string) ($this->path ?? $this->storage_path ?? $this->dropbox_path ?? ''));

        return $mediaType === 'extra' || str_contains($path, '/extra/');
    }

    public function isRequiredForEditing(): bool
    {
        return !$this->isExtra() || (bool) ($this->required_for_editing ?? false);
    }

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function serviceItem()
    {
        return $this->belongsTo(ShootService::class, 'shoot_service_id');
    }

    public function album()
    {
        return $this->belongsTo(ShootMediaAlbum::class, 'album_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function aiEditingJob()
    {
        return $this->belongsTo(AiEditingJob::class, 'ai_editing_job_id');
    }

    public function canMoveToCompleted()
    {
        return $this->workflow_stage === self::STAGE_TODO;
    }

    public function canVerify()
    {
        return $this->workflow_stage === self::STAGE_COMPLETED;
    }

    public function moveToCompleted($userId = null)
    {
        $this->workflow_stage = self::STAGE_COMPLETED;
        $this->moved_to_completed_at = now();
        $this->save();

        // Log the action
        $this->shoot->workflowLogs()->create([
            'user_id' => $userId ?? auth()->id(),
            'action' => 'file_moved_to_completed',
            'details' => "File '{$this->filename}' moved to completed folder",
            'metadata' => [
                'file_id' => $this->id,
                'filename' => $this->filename,
                'dropbox_path' => $this->dropbox_path
            ]
        ]);
    }

    public function verify($userId, $notes = null)
    {
        $this->workflow_stage = self::STAGE_VERIFIED;
        $this->verified_at = now();
        $this->verified_by = $userId;
        $this->verification_notes = $notes;
        $this->save();

        // Log the action
        $this->shoot->workflowLogs()->create([
            'user_id' => $userId,
            'action' => 'file_verified',
            'details' => "File '{$this->filename}' verified by admin",
            'metadata' => [
                'file_id' => $this->id,
                'filename' => $this->filename,
                'verification_notes' => $notes
            ]
        ]);
    }

    /**
     * Get public URL for media file based on paywall status
     */
    public function getPublicUrl(): ?string
    {
        $shoot = $this->shoot;

        if ($this->shoot_service_id) {
            $serviceItem = $this->relationLoaded('serviceItem')
                ? $this->serviceItem
                : $this->serviceItem()->with('shoot')->first();

            if ($serviceItem?->is_unlocked_for_delivery) {
                return $this->storage_path ?? $this->dropbox_path;
            }
        }

        // If bypass_paywall is true OR payment_status is paid, return original
        if ($shoot->bypass_paywall || $shoot->payment_status === 'paid') {
            return $this->storage_path ?? $this->dropbox_path;
        }

        // Otherwise return watermarked version if available
        if ($this->watermarked_storage_path) {
            return $this->watermarked_storage_path;
        }

        // If no watermarked version yet, return original but mark as restricted
        // Frontend should handle this appropriately
        return $this->storage_path ?? $this->dropbox_path;
    }

    /**
     * Check if file should be watermarked
     */
    public function shouldBeWatermarked(): bool
    {
        $shoot = $this->shoot;

        if ($this->shoot_service_id) {
            $serviceItem = $this->relationLoaded('serviceItem')
                ? $this->serviceItem
                : $this->serviceItem()->with('shoot')->first();

            if ($serviceItem?->is_unlocked_for_delivery) {
                return false;
            }
        }

        return !$shoot->bypass_paywall && $shoot->payment_status !== 'paid';
    }

    /**
     * Order files the way they must be delivered.
     *
     * `sort_order` is written 1-based by a saved manual reorder, so a positive
     * value means "an admin deliberately put this file at this position". Zero /
     * NULL means the file has never been placed (legacy rows, or a create that
     * opted out of positioning), and those must trail the curated block rather
     * than jumping to the front — a 0 would otherwise sort ahead of position 1
     * and silently push an unplaced file to the top of the client's download.
     *
     * The `id` tie-break makes the tail (and any duplicate positions left by a
     * partial reorder) deterministic, so two runs of the same archive build
     * always produce the same sequence.
     *
     * Applied explicitly at every call site rather than on the `files()`
     * relation, so ordering is a visible, intentional decision on the delivery
     * paths and unrelated reads keep their existing behavior.
     */
    public function scopeInDeliveryOrder(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->orderByRaw('CASE WHEN sort_order IS NULL OR sort_order <= 0 THEN 1 ELSE 0 END asc')
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc');
    }

    /**
     * The same ordering as scopeInDeliveryOrder(), for already-hydrated
     * collections that cannot be re-queried (e.g. an eager-loaded relation).
     *
     * @return array{0:int,1:int,2:int}
     */
    public function deliveryOrderKey(): array
    {
        $sortOrder = (int) ($this->sort_order ?? 0);

        return [$sortOrder > 0 ? 0 : 1, $sortOrder, (int) $this->id];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ShootFile>  $files
     * @return \Illuminate\Support\Collection<int, ShootFile>
     */
    public static function sortCollectionInDeliveryOrder(\Illuminate\Support\Collection $files): \Illuminate\Support\Collection
    {
        return $files
            ->sortBy(fn (ShootFile $file) => $file->deliveryOrderKey(), SORT_REGULAR)
            ->values();
    }
}
