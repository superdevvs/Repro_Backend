# Shoot Workflow Implementation Guide

This document outlines the comprehensive shoot workflow system implementation based on the specification.

## ✅ Completed Components

### 1. Database Schema Enhancements

**Migrations Created:**
- `2025_01_20_000000_enhance_shoots_table_for_workflow.php` - Adds `rep_id`, `bypass_paywall`, `tax_region`, `tax_percent`, `scheduled_at`, `completed_at`, `updated_by`
- `2025_01_20_000001_create_shoot_notes_table.php` - Notes with visibility and type
- `2025_01_20_000002_create_shoot_media_albums_table.php` - Media album structure
- `2025_01_20_000003_enhance_shoot_files_for_albums.php` - Links files to albums, adds watermarking fields
- `2025_01_20_000004_create_shoot_activity_logs_table.php` - Activity logging

**Key Fields Added:**
- `shoots.rep_id` - Sales rep assignment
- `shoots.bypass_paywall` - Boolean to bypass payment requirements
- `shoots.tax_region` - Enum: `md`, `dc`, `va`, `none`
- `shoots.tax_percent` - Tax percentage (0-100)
- `shoots.scheduled_at` - DateTime for scheduling
- `shoots.completed_at` - DateTime for completion
- `shoot_notes.type` - Enum: `shoot`, `company`, `photographer`, `editing`
- `shoot_notes.visibility` - Enum: `internal`, `photographer_only`, `client_visible`
- `shoot_media_albums` - Album structure with watermarking support
- `shoot_files.watermarked_storage_path` - Watermarked file path

### 2. Core Services

**ShootWorkflowService** (`app/Services/ShootWorkflowService.php`)
- State machine implementation with valid transitions
- Methods: `schedule()`, `start()`, `startEditing()`, `markReadyForReview()`, `markCompleted()`, `putOnHold()`, `cancel()`
- Validates transitions before applying
- Integrates with activity logging

**ShootActivityLogger** (`app/Services/ShootActivityLogger.php`)
- Centralized activity logging
- Generates human-readable descriptions
- Supports filtering and querying logs
- Used by workflow service for all state changes

### 3. Models

**New Models:**
- `ShootNote` - Notes with visibility rules
- `ShootMediaAlbum` - Media album structure
- `ShootActivityLog` - Activity logging

**Updated Models:**
- `Shoot` - Added relationships: `rep()`, `notes()`, `mediaAlbums()`, `activityLogs()`
- Added fillable fields for new columns

## 🔄 Next Steps

### 1. Booking Flow Enhancement

**File:** `app/Http/Controllers/API/ShootController.php` - `store()` method

**Required Changes:**
```php
public function store(Request $request)
{
    // Validate request
    $validated = $request->validate([
        'client_id' => $request->user()->role !== 'client' ? 'required|exists:users,id' : 'nullable',
        'rep_id' => 'nullable|exists:users,id',
        'photographer_id' => 'nullable|exists:users,id',
        'address' => 'required|string',
        'city' => 'required|string',
        'state' => 'required|string',
        'zip' => 'required|string',
        'services' => 'required|array',
        'services.*.id' => 'required|exists:services,id',
        'services.*.quantity' => 'integer|min:1',
        'scheduled_at' => 'nullable|date',
        'bypass_paywall' => 'boolean',
        'coupon_code' => 'nullable|string',
    ]);

    return DB::transaction(function () use ($validated, $request) {
        // 1. Calculate pricing from services
        $baseQuote = $this->calculateBaseQuote($validated['services']);
        
        // 2. Determine tax region from address
        $taxRegion = $this->determineTaxRegion($validated['state']);
        $taxPercent = $this->getTaxPercent($taxRegion);
        $taxAmount = $baseQuote * ($taxPercent / 100);
        $totalQuote = $baseQuote + $taxAmount;
        
        // 3. Check photographer availability if scheduled
        if ($validated['photographer_id'] && $validated['scheduled_at']) {
            $this->checkPhotographerAvailability(
                $validated['photographer_id'],
                $validated['scheduled_at']
            );
        }
        
        // 4. Create shoot
        $shoot = Shoot::create([
            'client_id' => $validated['client_id'] ?? $request->user()->id,
            'rep_id' => $validated['rep_id'] ?? $this->getClientRep($validated['client_id'] ?? $request->user()->id),
            'photographer_id' => $validated['photographer_id'] ?? null,
            'address' => $validated['address'],
            'city' => $validated['city'],
            'state' => $validated['state'],
            'zip' => $validated['zip'],
            'scheduled_at' => $validated['scheduled_at'] ? new \DateTime($validated['scheduled_at']) : null,
            'status' => $validated['scheduled_at'] ? ShootWorkflowService::STATUS_SCHEDULED : ShootWorkflowService::STATUS_HOLD_ON,
            'base_quote' => $baseQuote,
            'tax_region' => $taxRegion,
            'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount,
            'total_quote' => $totalQuote,
            'bypass_paywall' => $validated['bypass_paywall'] ?? false,
            'payment_status' => 'unpaid',
            'created_by' => $request->user()->name,
            'updated_by' => $request->user()->name,
        ]);
        
        // 5. Attach services
        foreach ($validated['services'] as $service) {
            $shoot->services()->attach($service['id'], [
                'quantity' => $service['quantity'] ?? 1,
                'price' => Service::find($service['id'])->price,
            ]);
        }
        
        // 6. Dispatch notification job (async)
        dispatch(new SendShootBookedNotifications($shoot));
        
        return response()->json(['data' => $this->transformShoot($shoot)], 201);
    });
}
```

### 2. Photographer Availability Checking

**Create:** `app/Services/PhotographerAvailabilityService.php`

```php
public function checkAvailability(int $photographerId, \DateTime $scheduledAt): bool
{
    // Check recurring availability rules
    // Check specific date availability
    // Check existing shoots for conflicts
    // Return true if available, false if conflict
}

public function getAvailableSlots(int $photographerId, \DateTime $from, \DateTime $to): array
{
    // Return array of available time slots
}
```

**Endpoint:** `GET /api/photographers/{id}/availability?from=...&to=...`

### 3. Notes API with Visibility

**File:** `app/Http/Controllers/API/ShootController.php` - Add methods:

```php
public function getNotes(Shoot $shoot)
{
    $user = auth()->user();
    $role = $user->role;
    
    $notes = $shoot->notes()->get()->filter(function ($note) use ($role) {
        return $note->isVisibleToRole($role);
    });
    
    return response()->json(['data' => $notes]);
}

public function storeNote(Request $request, Shoot $shoot)
{
    $validated = $request->validate([
        'type' => 'required|in:shoot,company,photographer,editing',
        'visibility' => 'required|in:internal,photographer_only,client_visible',
        'content' => 'required|string',
    ]);
    
    $note = $shoot->notes()->create([
        'author_id' => auth()->id(),
        'type' => $validated['type'],
        'visibility' => $validated['visibility'],
        'content' => $validated['content'],
    ]);
    
    return response()->json(['data' => $note], 201);
}
```

### 4. Watermarking Implementation

**Create Job:** `app/Jobs/GenerateWatermarkedImageJob.php`

```php
class GenerateWatermarkedImageJob implements ShouldQueue
{
    public function handle()
    {
        // Generate watermarked version
        // Store in watermarked_storage_path
        // Update album is_watermarked flag
    }
}
```

**Media Access Logic:**
- In `ShootController@show()` or media endpoints:
  - If `bypass_paywall = false` AND `payment_status != 'paid'`:
    - Return `watermarked_storage_path` if exists
    - Otherwise return protected/watermarked URL
  - If `bypass_paywall = true` OR `payment_status = 'paid'`:
    - Return `storage_path` (original)

### 5. Media Upload Flow

**Update:** `app/Http/Controllers/API/ShootController.php` - `uploadFiles()` method

```php
public function uploadFiles(Request $request, Shoot $shoot)
{
    // 1. Create upload batch
    $batch = MediaUploadBatch::create([
        'shoot_id' => $shoot->id,
        'photographer_id' => auth()->id(),
        'expected_file_count' => count($request->files),
    ]);
    
    // 2. Create or get album
    $album = $shoot->mediaAlbums()->firstOrCreate([
        'photographer_id' => auth()->id(),
        'source' => 'dropbox',
    ], [
        'folder_path' => "/shoots/{$shoot->id}/raw/{auth()->id()}/",
    ]);
    
    // 3. Dispatch upload jobs
    foreach ($request->files as $file) {
        dispatch(new UploadShootMediaToDropboxJob($shoot, $album, $file, $batch));
    }
    
    return response()->json(['batch_id' => $batch->id], 202);
}
```

### 6. Payment Status Updates

**Update:** Square webhook handler to:
- Update `shoot.payment_status` when payment completes
- Log activity via `ShootActivityLogger`
- If becomes `paid`, trigger watermark removal (or just stop serving watermarked)

### 7. Weekly Invoice Generation

**Create Job:** `app/Jobs/GenerateWeeklyInvoicesJob.php`

```php
class GenerateWeeklyInvoicesJob implements ShouldQueue
{
    public function handle()
    {
        // For each photographer/rep
        // Sum shoots from last week
        // Create invoice + invoice_items
        // Email PDF
    }
}
```

**Schedule in:** `app/Console/Kernel.php`

## 📋 Status Enum Mapping

The new status system maps to existing workflow_status:

| New Status | Workflow Status | Description |
|------------|----------------|-------------|
| `hold_on` | `on_hold` | No date assigned |
| `scheduled` | `booked`, `raw_upload_pending` | Date/time assigned |
| `in_progress` | `raw_uploaded` | Shoot in progress |
| `editing` | `editing`, `editing_uploaded` | In editing phase |
| `ready_for_review` | `pending_review` | Ready for admin review |
| `completed` | `completed`, `admin_verified` | Finalized |
| `cancelled` | - | Cancelled |

## 🔐 Role-Based Access

**Notes Visibility:**
- **Client**: Only `client_visible` + `type = shoot`
- **Photographer**: `photographer_only`, `client_visible`, `type = photographer`
- **Editor**: `type = editing`, `internal`
- **Admin/Super Admin**: All notes

**Media Access:**
- Enforced by `bypass_paywall` + `payment_status`
- Backend only - never trust frontend

## 🚀 Running Migrations

```bash
cd repro-backend
php artisan migrate
```

## 📝 Testing Checklist

- [ ] Booking flow with transaction
- [ ] Photographer availability conflict detection
- [ ] Notes visibility by role
- [ ] Watermarking on upload
- [ ] Payment status updates watermark access
- [ ] Activity logging for all state changes
- [ ] Weekly invoice generation

## 🔗 Integration Points

All external integrations (Dropbox, MightyCall, Square, etc.) should:
1. Be called from Jobs (async)
2. Have dedicated service classes
3. Handle retries and errors gracefully
4. Log activities via `ShootActivityLogger`

