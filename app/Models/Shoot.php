<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\Schedule\ScheduleDateScopeService;
use App\Services\Shoots\ShootListingService;

class Shoot extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'rep_id',
        'photographer_id',
        'editor_id',
        'service_id',
        'service_category',
        'address',
        'city',
        'state',
        'zip',
        'latitude',
        'longitude',
        'property_slug',
        'dropbox_raw_folder',
        'dropbox_extra_folder',
        'dropbox_edited_folder',
        'dropbox_archive_folder',
        'scheduled_date',
        'scheduled_at',
        'timezone',
        'completed_at',
        'time',
        'base_quote',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_amount',
        'total_quote',
        'payment_status',
        'delivery_status',
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
        'shoot_type',
        'service_area_kind',
        'service_area_value',
        'shoot_ready_notified_at',
        'cubicasa_idempotency_key',
        'product_status',
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
        'mls_image_width',
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
        'iguide_work_order_id',
        'iguide_data',
        'cubicasa_order_id',
        'cubicasa_external_id',
        'cubicasa_status',
        'cubicasa_product_type',
        'cubicasa_tour_url',
        'cubicasa_floorplans',
        'cubicasa_data',
        'cubicasa_last_synced_at',
        'cubicasa_last_status_at',
        'cubicasa_sync_status',
        'cubicasa_sync_job_id',
        'cubicasa_sync_started_at',
        'cubicasa_last_sync_error',
        'is_private_listing',
        'is_featured',
        'featured_homepage_title',
        'featured_homepage_location',
        'featured_homepage_subtitle',
        'featured_homepage_cta_label',
        'featured_homepage_cta_href',
        'is_listing_hidden',
        'listing_type',
        'property_status',
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
        // Hold request fields
        'hold_requested_at',
        'hold_requested_by',
        'hold_reason',
        // Photographer/sales rep payment tracking
        'photographer_paid_at',
        'photographer_paid_invoice_id',
        'sales_rep_paid_at',
        'sales_rep_paid_invoice_id',
        // External booking sync fields
        'alternate_scheduled_date',
        'alternate_time',
        'alternate_scheduled_at',
        'requested_photographers',
        'external_booking_payload',
        'external_booking_warnings',
        'external_booking_mapping_status',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'scheduled_at' => 'datetime',
        'timezone' => 'string',
        'latitude' => 'float',
        'longitude' => 'float',
        'completed_at' => 'datetime',
        'shoot_ready_notified_at' => 'datetime',
        'bypass_paywall' => 'boolean',
        'photos_uploaded_at' => 'datetime',
        'editing_completed_at' => 'datetime',
        'admin_verified_at' => 'datetime',
        'issues_resolved_at' => 'datetime',
        'submitted_for_review_at' => 'datetime',
        'is_flagged' => 'boolean',
        'base_quote' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
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
        'mls_image_width' => 'integer',
        'iguide_floorplans' => 'array',
        'iguide_data' => 'array',
        'cubicasa_floorplans' => 'array',
        'cubicasa_data' => 'array',
        'cubicasa_last_synced_at' => 'datetime',
        'cubicasa_last_status_at' => 'datetime',
        'cubicasa_sync_started_at' => 'datetime',
        'bright_mls_last_published_at' => 'datetime',
        'iguide_last_synced_at' => 'datetime',
        'is_private_listing' => 'boolean',
        'is_featured' => 'boolean',
        'is_listing_hidden' => 'boolean',
        'listing_type' => 'string',
        'property_status' => 'string',
        'mmm_last_punchout_at' => 'datetime',
        'mmm_last_order_at' => 'datetime',
        'approved_at' => 'datetime',
        'declined_at' => 'datetime',
        'tour_links' => 'array',
        'cancellation_requested_at' => 'datetime',
        'hold_requested_at' => 'datetime',
        'photographer_paid_at' => 'datetime',
        'sales_rep_paid_at' => 'datetime',
        // External booking sync fields (alternate_time and external_booking_mapping_status
        // remain plain strings — intentionally not cast).
        'alternate_scheduled_date' => 'date',
        'alternate_scheduled_at' => 'datetime',
        'requested_photographers' => 'array',
        'external_booking_payload' => 'array',
        'external_booking_warnings' => 'array',
    ];

    // Unified workflow status constants
    const STATUS_REQUESTED = 'requested'; // client-submitted, awaiting admin/rep approval
    const STATUS_SCHEDULED = 'scheduled'; // shoot is booked/approved
    const STATUS_UPLOADED = 'uploaded';   // photos uploaded by photographer/admin
    const STATUS_EDITING = 'editing';     // sent to editor, in progress
    const STATUS_REVIEW = 'review';       // editor submitted edits, awaiting editing manager review
    const STATUS_READY = 'ready';         // edited files uploaded, awaiting admin finalize
    const STATUS_DELIVERED = 'delivered'; // finalized and delivered to client
    const STATUS_ON_HOLD = 'on_hold';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_DECLINED = 'declined';   // admin/rep declined the shoot request

    public const SHOOT_TYPE_STANDARD = 'standard';
    public const SHOOT_TYPE_COMPLIMENTARY = 'complimentary';
    public const SHOOT_TYPE_SAMPLE_UPLOAD = 'sample_upload';
    public const SHOOT_TYPE_INTERNAL_TEST = 'internal_test';
    public const SHOOT_TYPE_PRICING_PENDING = 'pricing_pending';

    public const PRODUCT_STATUS_HAS_PRODUCT = 'has_product';
    public const PRODUCT_STATUS_NO_PRODUCT = 'no_product';
    public const PRODUCT_STATUS_ZERO_DOLLAR_PRODUCT = 'zero_dollar_product';

    // External booking auto-mapping status constants
    public const MAPPING_STATUS_FULLY_MAPPED = 'fully_mapped';
    public const MAPPING_STATUS_PARTIALLY_MAPPED = 'partially_mapped';
    public const MAPPING_STATUS_NEEDS_REVIEW = 'needs_review';

    public const INTERNAL_NO_CHARGE_SHOOT_TYPES = [
        self::SHOOT_TYPE_COMPLIMENTARY,
        self::SHOOT_TYPE_SAMPLE_UPLOAD,
        self::SHOOT_TYPE_INTERNAL_TEST,
        self::SHOOT_TYPE_PRICING_PENDING,
    ];

    // Legacy aliases (all map to the unified statuses above)
    const WORKFLOW_BOOKED = self::STATUS_SCHEDULED;
    const WORKFLOW_RAW_UPLOAD_PENDING = self::STATUS_SCHEDULED;
    const WORKFLOW_RAW_UPLOADED = self::STATUS_UPLOADED;
    const WORKFLOW_RAW_ISSUE = self::STATUS_UPLOADED;
    const WORKFLOW_EDITING = self::STATUS_EDITING;
    const WORKFLOW_READY_FOR_CLIENT = self::STATUS_READY;
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

    /**
     * Whether this shoot has at least one booked service that produces iGUIDE
     * deliverables (floor plans / iGuide tours). Used to gate auto sync &
     * ingestion so we don't pull iGuide data for shoots that didn't book
     * floorplan / 2D floorplan / 3D floorplan / iGuide services.
     */
    public function hasIguideEligibleService(): bool
    {
        $needles = ['iguide', 'floorplan', 'floor plan'];

        $matches = static function (?string $value) use ($needles): bool {
            if (!is_string($value) || $value === '') {
                return false;
            }
            $haystack = strtolower($value);
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }
            return false;
        };

        // Fast check on the legacy single-service columns.
        if ($matches($this->service_category)) {
            return true;
        }
        if ($this->service && $matches($this->service->name)) {
            return true;
        }

        // Per-service rows in the shoot_service pivot.
        $services = $this->relationLoaded('services')
            ? $this->services
            : $this->services()->with('category')->get();

        foreach ($services as $service) {
            if ($matches($service->name)) {
                return true;
            }
            $categoryName = $service->category?->name;
            if ($matches($categoryName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this shoot has at least one booked service that produces CubiCasa
     * deliverables (CubiCasa scans, GLA, 2D/3D floor plans). Used to gate auto
     * sync & ingestion so we don't pull CubiCasa data for shoots that didn't
     * book a relevant service.
     */
    public function hasCubiCasaEligibleService(): bool
    {
        $needles = ['cubicasa', 'cubi casa', 'cubi-casa', 'scan', 'floorplan', 'floor plan', 'gla', '2d floor', '3d floor'];

        $matches = static function (?string $value) use ($needles): bool {
            if (!is_string($value) || $value === '') {
                return false;
            }
            $haystack = strtolower($value);
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }
            return false;
        };

        if ($matches($this->service_category)) {
            return true;
        }
        if ($this->service && $matches($this->service->name)) {
            return true;
        }

        $services = $this->relationLoaded('services')
            ? $this->services
            : $this->services()->with('category')->get();

        foreach ($services as $service) {
            if ($matches($service->name)) {
                return true;
            }
            if ($matches($service->category?->name)) {
                return true;
            }
        }

        return false;
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'shoot_service')
            ->withPivot([
                'id',
                'price',
                'quantity',
                'photographer_pay',
                'photographer_id',
                'editor_id',
                'editing_completed_at',
                'scheduled_at',
                'workflow_status',
                'delivery_status',
                'ready_at',
                'delivered_at',
                'cancelled_at',
                'is_deliverable',
                'force_unlock_delivery',
                'unlock_reason',
                'unlocked_by',
            ])
            ->withTimestamps();
    }

    public function serviceItems()
    {
        return $this->hasMany(ShootService::class);
    }

    public function ghostUsers()
    {
        return $this->belongsToMany(User::class, 'shoot_ghost_users')
            ->withTimestamps();
    }

    /**
     * BelongsToMany relationship to photographers via shoot_service pivot
     * This enables easy payout grouping by photographer
     */
    public function servicePhotographers()
    {
        return $this->belongsToMany(User::class, 'shoot_service', 'shoot_id', 'photographer_id')
            ->withPivot(['service_id', 'price', 'quantity', 'photographer_pay'])
            ->withTimestamps();
    }

    /**
     * Get all unique photographers assigned to services in this shoot
     * Returns a collection of User models
     */
    public function getUniqueServicePhotographers(): \Illuminate\Support\Collection
    {
        return User::whereIn('id', function ($query) {
            $query->select('photographer_id')
                ->from('shoot_service')
                ->where('shoot_id', $this->id)
                ->whereNotNull('photographer_id');
        })->get();
    }

    /**
     * Get photographer pay grouped by photographer ID
     * Uses fallback: service.photographer_id ?? shoot.photographer_id
     * 
     * Returns: Collection [photographer_id => total_pay]
     * 
     * THIS IS THE CORRECT METHOD FOR PAYOUT CALCULATIONS
     */
    public function getPhotographerPayByPhotographer(): \Illuminate\Support\Collection
    {
        if (!$this->relationLoaded('services')) {
            $this->load('services');
        }

        if (is_array($this->services)) {
            return collect($this->services)
                ->groupBy(function ($service) {
                    return $service['resolved_photographer_id'] ?? $service['photographer_id'] ?? null;
                })
                ->map(function ($services) {
                    return $services->sum(function ($service) {
                        $pay = $service['photographer_pay'] ?? 0;
                        $quantity = $service['quantity'] ?? 1;
                        return (float) $pay * (int) $quantity;
                    });
                })->filter(function ($pay, $photographerId) {
                    return $photographerId !== null;
                });
        }

        $fallbackPhotographerId = $this->photographer_id;

        return $this->services->groupBy(function ($service) use ($fallbackPhotographerId) {
            // FALLBACK RULE: service photographer_id ?? shoot photographer_id
            return $service->pivot->photographer_id ?? $fallbackPhotographerId;
        })->map(function ($services) {
            return $services->sum(function ($service) {
                $pay = $service->pivot->photographer_pay ?? 0;
                $quantity = $service->pivot->quantity ?? 1;
                return (float) $pay * $quantity;
            });
        })->filter(function ($pay, $photographerId) {
            // Filter out null photographer entries
            return $photographerId !== null;
        });
    }

    /**
     * Get resolved photographer for a service (with fallback)
     * Returns photographer_id from pivot, or falls back to shoot.photographer_id
     */
    public function getResolvedPhotographerForService(int $serviceId): ?int
    {
        $service = $this->services->firstWhere('id', $serviceId);
        if (!$service) {
            return $this->photographer_id;
        }
        
        return $service->pivot->photographer_id ?? $this->photographer_id;
    }

    /**
     * Get the photographer assigned to a specific service
     */
    public function getPhotographerForService(int $serviceId): ?User
    {
        $pivot = DB::table('shoot_service')
            ->where('shoot_id', $this->id)
            ->where('service_id', $serviceId)
            ->first();

        if (!$pivot || !$pivot->photographer_id) {
            return null;
        }

        return User::find($pivot->photographer_id);
    }

    /**
     * Get service-photographer mapping for this shoot
     * Returns array of [service_id => photographer_id]
     */
    public function getServicePhotographerMap(): array
    {
        return DB::table('shoot_service')
            ->where('shoot_id', $this->id)
            ->whereNotNull('photographer_id')
            ->pluck('photographer_id', 'service_id')
            ->toArray();
    }

    /**
     * Assign a photographer to a specific service in this shoot
     */
    public function assignPhotographerToService(int $serviceId, ?int $photographerId): bool
    {
        return DB::table('shoot_service')
            ->where('shoot_id', $this->id)
            ->where('service_id', $serviceId)
            ->update(['photographer_id' => $photographerId]) > 0;
    }

    public function assignEditorToService(int $serviceId, ?int $editorId, ?string $editingCompletedAt = null): bool
    {
        return DB::table('shoot_service')
            ->where('shoot_id', $this->id)
            ->where('service_id', $serviceId)
            ->update([
                'editor_id' => $editorId,
                'editing_completed_at' => $editingCompletedAt,
            ]) > 0;
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

    public function featuredHomepageImages()
    {
        return $this->hasMany(FeaturedShootImage::class)->orderBy('sort_order');
    }

    public function shareLinks()
    {
        return $this->hasMany(ShootShareLink::class);
    }

    public function publicPaymentAccessTokens()
    {
        return $this->hasMany(PublicPaymentAccessToken::class);
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

    public function getCanonicalCompletedPayments()
    {
        $payments = $this->relationLoaded('payments')
            ? $this->payments
            : $this->payments()->get();

        return $payments
            ->filter(fn (Payment $payment) => $payment->status === Payment::STATUS_COMPLETED)
            ->unique(fn (Payment $payment) => $this->resolvePaymentDeduplicationKey($payment))
            ->values();
    }

    public function calculateCanonicalTotalPaid(): float
    {
        return (float) $this->getCanonicalCompletedPayments()->sum(
            fn (Payment $payment) => (float) $payment->amount
        );
    }

    public function syncPaymentStatusFromRecords(?string $paymentType = null): array
    {
        $previousStatus = $this->payment_status;
        $totalPaid = $this->calculateCanonicalTotalPaid();
        $totalQuote = (float) ($this->total_quote ?? 0);
        $newStatus = $totalQuote <= 0.01
            ? 'paid'
            : ($totalPaid <= 0 ? 'unpaid' : ($totalPaid >= $totalQuote ? 'paid' : 'partial'));

        $dirty = false;

        if ($this->payment_status !== $newStatus) {
            $this->payment_status = $newStatus;
            $dirty = true;
        }

        if ($paymentType !== null && $paymentType !== '' && $this->payment_type !== $paymentType) {
            $this->payment_type = $paymentType;
            $dirty = true;
        }

        if ($dirty) {
            $this->save();
        }

        // Stop-on-paid seam (Requirements 5.3, 5.4): when a payment is recorded that flips this
        // shoot to fully paid, proactively cancel any pending payment reminders rather than waiting
        // for the dispatch-time race guard to catch them. Guarded to fire only on the transition to
        // paid so repeated syncs on an already-paid shoot do not re-run cancellation. The dispatch
        // re-check in DispatchScheduledMessages remains the safety net. AutomationService is
        // resolved via the container to avoid coupling the model to the messaging service graph.
        $becamePaid = strtolower((string) $previousStatus) !== 'paid' && $newStatus === 'paid';
        if ($becamePaid) {
            try {
                app(\App\Services\Messaging\AutomationService::class)->cancelPaymentReminders($this);
            } catch (\Throwable $exception) {
                Log::warning('Failed to cancel payment reminders after shoot became paid', [
                    'shoot_id' => $this->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $overpaymentAmount = $totalPaid - $totalQuote;
        if ($totalQuote > 0 && $overpaymentAmount > 0.01) {
            Log::warning('Shoot payment overage detected', [
                'shoot_id' => $this->id,
                'total_quote' => $totalQuote,
                'total_paid' => $totalPaid,
                'overpayment_amount' => round($overpaymentAmount, 2),
                'payment_ids' => $this->getCanonicalCompletedPayments()->pluck('id')->all(),
            ]);
        }

        return [
            'total_paid' => $totalPaid,
            'payment_status' => $newStatus,
            'remaining_balance' => max($totalQuote - $totalPaid, 0),
        ];
    }

    public function isNoChargeShoot(): bool
    {
        return (float) ($this->total_quote ?? 0) <= 0.01;
    }

    public function isInternalNoProductShoot(): bool
    {
        return in_array((string) ($this->shoot_type ?? self::SHOOT_TYPE_STANDARD), self::INTERNAL_NO_CHARGE_SHOOT_TYPES, true);
    }

    public function allowsNoMediaDelivery(): bool
    {
        return $this->isInternalNoProductShoot() || $this->isNoChargeShoot();
    }

    public function syncServiceItemRollups(): array
    {
        $items = $this->serviceItems()->get();

        if ($items->isEmpty()) {
            return [
                'delivery_status' => $this->delivery_status ?? 'not_started',
                'workflow_status' => $this->workflow_status,
            ];
        }

        $deliverableItems = $items->filter(function (ShootService $item) {
            return $item->is_deliverable
                && $item->workflow_status !== ShootService::WORKFLOW_CANCELLED
                && $item->delivery_status !== ShootService::DELIVERY_CANCELLED;
        });

        if ($deliverableItems->isEmpty()) {
            $deliveryStatus = 'delivered';
        } elseif ($deliverableItems->every(fn (ShootService $item) => $item->delivery_status === ShootService::DELIVERY_DELIVERED)) {
            $deliveryStatus = 'delivered';
        } elseif ($deliverableItems->contains(fn (ShootService $item) => $item->delivery_status === ShootService::DELIVERY_DELIVERED)) {
            $deliveryStatus = 'partially_delivered';
        } else {
            $deliveryStatus = 'not_started';
        }

        if ($this->delivery_status !== $deliveryStatus) {
            $this->delivery_status = $deliveryStatus;
            $this->save();
        }

        return [
            'delivery_status' => $deliveryStatus,
            'workflow_status' => $this->workflow_status,
        ];
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

    /**
     * Scheduled payment reminders for this shoot (Req 12).
     */
    public function paymentReminders()
    {
        return $this->hasMany(PaymentReminder::class);
    }

    // Helper methods
    public function getTotalPaidAttribute()
    {
        return $this->calculateCanonicalTotalPaid();
    }

    public function getRemainingBalanceAttribute()
    {
        return max((float) ($this->total_quote ?? 0) - (float) ($this->total_paid ?? 0), 0);
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
        
        if (is_array($this->services)) {
            return (float) collect($this->services)->sum(function ($service) {
                $pay = $service['photographer_pay'] ?? 0;
                $quantity = $service['quantity'] ?? 1;
                
                return (float) $pay * (int) $quantity;
            });
        }
        
        return (float) $this->services->sum(function ($service) {
            $pivotPay = $service->pivot->photographer_pay ?? null;
            $quantity = $service->pivot->quantity ?? 1;
            
            // Fallback to service-level default photographer_pay
            $pay = ($pivotPay !== null && $pivotPay !== '')
                ? (float) $pivotPay
                : (float) ($service->photographer_pay ?? 0);
            
            return $pay * $quantity;
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

    private function resolvePaymentDeduplicationKey(Payment $payment): string
    {
        if (!empty($payment->stripe_session_id)) {
            return 'stripe_session:' . $payment->stripe_session_id;
        }

        if (!empty($payment->stripe_payment_id)) {
            return 'stripe_payment:' . $payment->stripe_payment_id;
        }

        if (!empty($payment->square_payment_id)) {
            return 'square_payment:' . $payment->square_payment_id;
        }

        return 'payment_id:' . $payment->id;
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
            case self::STATUS_REVIEW:
                if (!$this->submitted_for_review_at) {
                    $this->submitted_for_review_at = now();
                }
                break;
            case self::STATUS_READY:
                $this->editing_completed_at = now();
                break;
            case self::STATUS_DELIVERED:
                $this->admin_verified_at = now();
                $this->verified_by = $userId;
                $this->completed_at = now();
                $this->delivery_status = 'delivered';
                if (!$this->editing_completed_at) {
                    $this->editing_completed_at = now();
                }
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
        ShootListingService::flushCachedListings();

        try {
            app(ScheduleDateScopeService::class)->invalidateShootBuckets($shoot);
        } catch (\Throwable $exception) {
            Log::warning('Could not invalidate schedule date caches', [
                'shoot_id' => $shootId,
                'error' => $exception->getMessage(),
            ]);
        }
        
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
            ->when(
                Schema::hasColumn('shoot_files', 'is_extra') && Schema::hasColumn('shoot_files', 'required_for_editing'),
                fn ($query) => $query->where(function ($scope) {
                    $scope->where(function ($nested) {
                        $nested->where('is_extra', false)->orWhereNull('is_extra');
                    })->orWhere('required_for_editing', true);
                }),
                fn ($query) => $query->where(function ($scope) {
                    $scope->whereNull('media_type')->orWhere('media_type', '!=', 'extra');
                })
            )
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
            ->where(function ($query) {
                $query->whereNull('flag_reason')
                    ->orWhere('flag_reason', '');
            })
            ->when(
                Schema::hasColumn('shoot_files', 'is_extra'),
                fn ($query) => $query->where('is_extra', true),
                fn ($query) => $query->where(function ($scope) {
                    $scope->where('media_type', 'extra')
                        ->orWhere('path', 'like', '%/extra/%');
                })
            )
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
