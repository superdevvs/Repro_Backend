<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Shoot extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'rep_id',
        'photographer_id',
        'service_id',
        'service_category',
        'address',
        'city',
        'state',
        'zip',
        'property_slug',
        'dropbox_raw_folder',
        'dropbox_extra_folder',
        'dropbox_edited_folder',
        'dropbox_archive_folder',
        'scheduled_date',
        'scheduled_at',
        'completed_at',
        'time',
        'base_quote',
        'tax_amount',
        'total_quote',
        'payment_status',
        'payment_type',
        'bypass_paywall',
        'tax_region',
        'tax_percent',
        'notes',
        'shoot_notes',
        'company_notes',
        'photographer_notes',
        'editor_notes',
        'admin_issue_notes',
        'status',
        'workflow_status',
        'created_by',
        'updated_by',
        'photos_uploaded_at',
        'editing_completed_at',
        'admin_verified_at',
        'verified_by',
        'is_flagged',
        'issues_resolved_at',
        'issues_resolved_by',
        'submitted_for_review_at',
        'package_name',
        'package_services_included',
        'expected_final_count',
        'bracket_mode',
        'expected_raw_count',
        'raw_photo_count',
        'edited_photo_count',
        'extra_photo_count',
        'raw_missing_count',
        'edited_missing_count',
        'missing_raw',
        'missing_final',
        'hero_image',
        'weather_summary',
        'weather_temperature',
        // Integration fields
        'mls_id',
        'listing_source',
        'property_details',
        'integration_flags',
        'bright_mls_publish_status',
        'bright_mls_last_published_at',
        'bright_mls_response',
        'bright_mls_manifest_id',
        'iguide_tour_url',
        'iguide_floorplans',
        'iguide_last_synced_at',
        'iguide_property_id',
        'is_private_listing',
        // MMM Integration
        'mmm_status',
        'mmm_order_number',
        'mmm_buyer_cookie',
        'mmm_redirect_url',
        'mmm_last_punchout_at',
        'mmm_last_order_at',
        'mmm_last_error',
        // Approval workflow fields
        'approval_notes',
        'approved_at',
        'approved_by',
        'declined_at',
        'declined_by',
        'declined_reason',
        // Cancellation request fields
        'cancellation_requested_at',
        'cancellation_requested_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
        'bypass_paywall' => 'boolean',
        'photos_uploaded_at' => 'datetime',
        'editing_completed_at' => 'datetime',
        'admin_verified_at' => 'datetime',
        'issues_resolved_at' => 'datetime',
        'submitted_for_review_at' => 'datetime',
        'is_flagged' => 'boolean',
        'base_quote' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_quote' => 'decimal:2',
        'expected_final_count' => 'integer',
        'bracket_mode' => 'integer',
        'expected_raw_count' => 'integer',
        'raw_photo_count' => 'integer',
        'edited_photo_count' => 'integer',
        'extra_photo_count' => 'integer',
        'raw_missing_count' => 'integer',
        'edited_missing_count' => 'integer',
        'missing_raw' => 'boolean',
        'missing_final' => 'boolean',
        'package_services_included' => 'array',
        'property_details' => 'array',
        'integration_flags' => 'array',
        'iguide_floorplans' => 'array',
        'bright_mls_last_published_at' => 'datetime',
        'iguide_last_synced_at' => 'datetime',
        'is_private_listing' => 'boolean',
        'mmm_last_punchout_at' => 'datetime',
        'mmm_last_order_at' => 'datetime',
        'approved_at' => 'datetime',
        'declined_at' => 'datetime',
        'tour_links' => 'array',
        'cancellation_requested_at' => 'datetime',
    ];

    // Unified workflow status constants
    const STATUS_REQUESTED = 'requested'; // client-submitted, awaiting admin/rep approval
    const STATUS_SCHEDULED = 'scheduled'; // shoot is booked/approved
    const STATUS_UPLOADED = 'uploaded';   // photos uploaded by photographer/admin
    const STATUS_EDITING = 'editing';     // sent to editor, in progress
    const STATUS_DELIVERED = 'delivered'; // finalized and delivered to client
    const STATUS_ON_HOLD = 'on_hold';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_DECLINED = 'declined';   // admin/rep declined the shoot request

    // Legacy aliases (all map to the unified statuses above)
    const WORKFLOW_BOOKED = self::STATUS_SCHEDULED;
    const WORKFLOW_RAW_UPLOAD_PENDING = self::STATUS_SCHEDULED;
    const WORKFLOW_RAW_UPLOADED = self::STATUS_UPLOADED;
    const WORKFLOW_RAW_ISSUE = self::STATUS_UPLOADED;
    const WORKFLOW_EDITING = self::STATUS_EDITING;
    const WORKFLOW_READY_FOR_CLIENT = self::STATUS_DELIVERED;
    const WORKFLOW_ON_HOLD = self::STATUS_ON_HOLD;
    const WORKFLOW_ADMIN_VERIFIED = self::STATUS_DELIVERED;
    const WORKFLOW_COMPLETED = self::STATUS_DELIVERED;

    // Backwards compatibility - 'completed' maps to 'uploaded'
    const STATUS_COMPLETED = self::STATUS_UPLOADED;

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function photographer()
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'shoot_service')
            ->withPivot(['price', 'quantity', 'photographer_pay'])
            ->withTimestamps();
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function issuesResolvedBy()
    {
        return $this->belongsTo(User::class, 'issues_resolved_by');
    }

    public function files()
    {
        return $this->hasMany(ShootFile::class);
    }

    public function shareLinks()
    {
        return $this->hasMany(ShootShareLink::class);
    }

    public function activeShareLinks()
    {
        return $this->shareLinks()
            ->where('is_revoked', false)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices()
    {
        return $this->belongsToMany(Invoice::class, 'invoice_shoot')->withTimestamps();
    }

    public function dropboxFolders()
    {
        return $this->hasMany(DropboxFolder::class);
    }

    public function workflowLogs()
    {
        return $this->hasMany(WorkflowLog::class);
    }

    public function rescheduleRequests()
    {
        return $this->hasMany(ShootRescheduleRequest::class);
    }

    public function mmmPunchoutSessions()
    {
        return $this->hasMany(
            \App\Models\MmmPunchoutSession::class
        );
    }

    public function messages()
    {
        return $this->hasMany(ShootMessage::class);
    }

    public function rep()
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notes()
    {
        return $this->hasMany(ShootNote::class);
    }

    public function mediaAlbums()
    {
        return $this->hasMany(ShootMediaAlbum::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ShootActivityLog::class);
    }

    // Helper methods
    public function getTotalPaidAttribute()
    {
        return $this->payments->where('status', 'completed')->sum('amount');
    }

    public function getRemainingBalanceAttribute()
    {
        return $this->total_quote - $this->total_paid;
    }

    /**
     * Calculate total photographer pay from services
     * Sums up photographer_pay from all services in the shoot
     */
    public function getTotalPhotographerPayAttribute(): float
    {
        if (!$this->relationLoaded('services')) {
            $this->load('services');
        }
        
        return (float) $this->services->sum(function ($service) {
            $photographerPay = $service->pivot->photographer_pay ?? null;
            $quantity = $service->pivot->quantity ?? 1;
            
            if ($photographerPay === null) {
                return 0;
            }
            
            return (float) $photographerPay * $quantity;
        });
    }

    /**
     * Get photographer pay for a specific service
     */
    public function getPhotographerPayForService(int $serviceId): ?float
    {
        $service = $this->services->firstWhere('id', $serviceId);
        if (!$service || !$service->pivot) {
            return null;
        }
        
        return $service->pivot->photographer_pay ? (float) $service->pivot->photographer_pay : null;
    }

    /**
     * Get company logo for watermarking from photographer's or rep's branding
     * Returns the logo URL from user_branding table
     */
    public function getCompanyLogoForWatermark(): ?string
    {
        // Try photographer first
        if ($this->photographer_id) {
            $branding = DB::table('user_branding')
                ->where('user_id', $this->photographer_id)
                ->whereNotNull('logo')
                ->first();
            
            if ($branding && $branding->logo) {
                return $branding->logo;
            }
        }

        // Fallback to rep's logo
        if ($this->rep_id) {
            $branding = DB::table('user_branding')
                ->where('user_id', $this->rep_id)
                ->whereNotNull('logo')
                ->first();
            
            if ($branding && $branding->logo) {
                return $branding->logo;
            }
        }

        // Fallback to default REPRO logo (return local path prefixed with 'local:')
        $defaultLogo = public_path('images/repro-logo.png');
        if (file_exists($defaultLogo)) {
            return 'local:' . $defaultLogo;
        }

        // Return null if no logo found (will fallback to text watermark)
        return null;
    }

    public function canUploadPhotos()
    {
        // Allow raw uploads until admin moves the shoot past raw review
        return in_array($this->workflow_status, [
            self::STATUS_SCHEDULED,
            self::STATUS_COMPLETED,
            self::STATUS_UPLOADED,
        ]);
    }

    public function canMoveToCompleted()
    {
        return in_array($this->workflow_status, [
            self::STATUS_UPLOADED,
            self::STATUS_EDITING,
        ]);
    }

    public function updateWorkflowStatus($status, $userId = null)
    {
        $oldStatus = $this->workflow_status;
        $this->workflow_status = $status;
        $this->status = $status;

        // Set timestamps based on status
        switch ($status) {
            case self::STATUS_COMPLETED:
            case self::STATUS_UPLOADED:
                $this->photos_uploaded_at = now();
                break;
            case self::STATUS_DELIVERED:
                $this->admin_verified_at = now();
                $this->verified_by = $userId;
                $this->completed_at = now();
                $this->editing_completed_at = now();
                break;
        }

        $this->save();

        // Log the workflow change
        $this->workflowLogs()->create([
            'user_id' => $userId ?? auth()->id(),
            'action' => "status_changed_to_{$status}",
            'details' => "Workflow status changed from {$oldStatus} to {$status}",
            'metadata' => [
                'old_status' => $oldStatus,
                'new_status' => $status,
                'timestamp' => now()->toISOString()
            ]
        ]);
    }

    /**
     * Boot the model and set up cache invalidation
     */
    protected static function boot()
    {
        parent::boot();

        // Invalidate caches when shoot is updated
        static::saved(function ($shoot) {
            static::invalidateCaches($shoot);
        });

        static::deleted(function ($shoot) {
            static::invalidateCaches($shoot);
        });
    }

    /**
     * Invalidate all caches related to this shoot
     */
    protected static function invalidateCaches(Shoot $shoot)
    {
        $shootId = $shoot->id;
        
        // Invalidate shoot-specific caches
        Cache::forget("shoot_files_{$shootId}_raw");
        Cache::forget("shoot_files_{$shootId}_edited");
        Cache::forget("shoot_files_{$shootId}_all");
        
        // Invalidate dashboard caches - clear all dashboard overview caches
        // Since we can't easily get all keys, we'll let them expire naturally
        // or use a more targeted approach if cache tags are available
        
        // Invalidate shoots list caches - pattern-based clearing
        // Note: This is a simple approach. For production, consider using cache tags
        try {
            $store = Cache::getStore();
            if (method_exists($store, 'getRedis')) {
                $redis = $store->getRedis();
                if (method_exists($redis, 'keys')) {
                    // Invalidate dashboard caches
                    $dashboardKeys = $redis->keys('dashboard_overview_*');
                    foreach ($dashboardKeys as $key) {
                        Cache::forget(str_replace(config('cache.prefix', '') . ':', '', $key));
                    }
                    
                    // Invalidate shoots list caches
                    $shootsKeys = $redis->keys('shoots_index_*');
                    foreach ($shootsKeys as $key) {
                        Cache::forget(str_replace(config('cache.prefix', '') . ':', '', $key));
                    }
                    
                    // Invalidate notifications caches
                    $notificationsKeys = $redis->keys('notifications_*');
                    foreach ($notificationsKeys as $key) {
                        Cache::forget(str_replace(config('cache.prefix', '') . ':', '', $key));
                    }
                }
            }
        } catch (\Exception $e) {
            // If cache store doesn't support key scanning, just log and continue
            \Log::warning('Could not invalidate cache keys', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Generate property slug from address components
     */
    public function generatePropertySlug()
    {
        $parts = [
            $this->address,
            $this->city,
            $this->state,
            $this->zip
        ];

        // Clean and join parts
        $slug = collect($parts)
            ->filter()
            ->map(function ($part) {
                // Remove special characters and replace spaces with hyphens
                $clean = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $part);
                return preg_replace('/\s+/', '-', trim($clean));
            })
            ->filter()
            ->implode('-');

        // Clean up multiple hyphens and convert to lowercase
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = strtolower(trim($slug, '-'));

        // Limit length
        return substr($slug, 0, 150);
    }

    /**
     * Get Dropbox folder path for a specific type
     */
    public function getDropboxFolderForType(string $type): ?string
    {
        switch ($type) {
            case 'raw':
                return $this->dropbox_raw_folder;
            case 'extra':
                return $this->dropbox_extra_folder;
            case 'edited':
                // Use archive folder if available, otherwise use edited folder
                return $this->dropbox_archive_folder ?: $this->dropbox_edited_folder;
            case 'archive':
                return $this->dropbox_archive_folder;
            default:
                return null;
        }
    }

    /**
     * Update photo counts based on files
     */
    public function updatePhotoCounts()
    {
        $this->raw_photo_count = $this->files()
            ->where('workflow_stage', 'todo')
            ->where(function ($query) {
                $query->whereNull('flag_reason')
                    ->orWhere('flag_reason', '');
            })
            ->count();

        $this->edited_photo_count = $this->files()
            ->whereIn('workflow_stage', ['completed', 'verified'])
            ->where(function ($query) {
                $query->whereNull('flag_reason')
                    ->orWhere('flag_reason', '');
            })
            ->count();

        $this->extra_photo_count = $this->files()
            ->where('workflow_stage', 'todo')
            ->where('path', 'like', '%/extra/%')
            ->count();

        // Calculate missing counts
        if ($this->expected_raw_count > 0) {
            $this->raw_missing_count = max(0, $this->expected_raw_count - $this->raw_photo_count);
            $this->missing_raw = $this->raw_missing_count > 0;
        }

        if ($this->expected_final_count > 0) {
            $this->edited_missing_count = max(0, $this->expected_final_count - $this->edited_photo_count);
            $this->missing_final = $this->edited_missing_count > 0;
        }

        $this->save();
    }
}
