# Migration and Model Verification Checklist

## ✅ Database Migrations

Run the following to verify all migrations:

```bash
cd repro-backend
php artisan migrate
```

### Expected Tables After Migration:

1. **shoots** table should have:
   - ✅ `rep_id` (nullable foreign key to users)
   - ✅ `bypass_paywall` (boolean, default false)
   - ✅ `tax_region` (enum: md, dc, va, none)
   - ✅ `tax_percent` (decimal 5,2)
   - ✅ `scheduled_at` (datetime, nullable)
   - ✅ `completed_at` (timestamp, nullable)
   - ✅ `updated_by` (string, nullable)

2. **shoot_notes** table should exist with:
   - ✅ `id`, `shoot_id`, `author_id`
   - ✅ `type` (enum: shoot, company, photographer, editing)
   - ✅ `visibility` (enum: internal, photographer_only, client_visible)
   - ✅ `content` (text)

3. **shoot_media_albums** table should exist with:
   - ✅ `id`, `shoot_id`, `photographer_id`
   - ✅ `source` (enum: dropbox, local)
   - ✅ `folder_path`, `cover_image_path`
   - ✅ `is_watermarked` (boolean)

4. **shoot_files** table should have:
   - ✅ `album_id` (nullable foreign key)
   - ✅ `media_type` (enum: raw, edited, video, iguide, extra)
   - ✅ `storage_path` (string)
   - ✅ `watermarked_storage_path` (nullable string)
   - ✅ `mime_type` (string)
   - ✅ `uploaded_at` (timestamp)

5. **shoot_activity_logs** table should exist with:
   - ✅ `id`, `shoot_id`, `user_id`
   - ✅ `action` (string)
   - ✅ `description` (text, nullable)
   - ✅ `metadata` (json, nullable)

## ✅ Model Relationships

### Shoot Model

Verify these relationships exist:

```php
$shoot->rep()              // BelongsTo User (rep_id)
$shoot->notes()            // HasMany ShootNote
$shoot->mediaAlbums()      // HasMany ShootMediaAlbum
$shoot->activityLogs()     // HasMany ShootActivityLog
```

### ShootFile Model

Verify:
```php
$file->album()             // BelongsTo ShootMediaAlbum
```

### New Models

Verify these models exist and are properly namespaced:
- ✅ `App\Models\ShootNote`
- ✅ `App\Models\ShootMediaAlbum`
- ✅ `App\Models\ShootActivityLog`

## ✅ Service Classes

Verify these services exist:
- ✅ `App\Services\ShootWorkflowService`
- ✅ `App\Services\ShootActivityLogger`
- ✅ `App\Services\ShootTaxService`

## ✅ API Endpoints

### Booking Endpoint

**POST** `/api/shoots`

**Request (Admin):**
```json
{
  "client_id": 1,
  "rep_id": 2,
  "photographer_id": 3,
  "address": "123 Main St",
  "city": "Baltimore",
  "state": "MD",
  "zip": "21201",
  "services": [
    {"id": 1, "quantity": 1}
  ],
  "scheduled_at": "2025-01-27 10:00:00",
  "bypass_paywall": false,
  "shoot_notes": "Client visible note",
  "company_notes": "Internal note"
}
```

**Request (Client):**
```json
{
  "address": "123 Main St",
  "city": "Washington",
  "state": "DC",
  "zip": "20001",
  "services": [
    {"id": 1, "quantity": 1}
  ],
  "bypass_paywall": true
}
```

**Response:**
- Status: 201 Created
- Body: `ShootResource` with all shoot data

## ✅ Testing

Run tests:

```bash
php artisan test --filter=ShootBookingTest
```

Expected test results:
- ✅ Admin can book shoot with date and photographer → status = scheduled
- ✅ Admin can book hold-on shoot without date → status = hold_on
- ✅ Client can book shoot with bypass_paywall = true
- ✅ Tax is calculated correctly for Maryland (6%)
- ✅ Notes are created with correct visibility
- ✅ Client cannot book for another client (403)
- ✅ Booking fails if photographer has conflict (422)
- ✅ Booking creates activity log

## 🔧 Troubleshooting

### Migration Errors

If you get foreign key errors:
1. Check that `users` table exists
2. Check that `services` table exists
3. Run migrations in order (they're timestamped)

### Model Not Found Errors

If you get "Class not found" errors:
1. Run `composer dump-autoload`
2. Check namespaces match file locations
3. Verify models are in `app/Models/` directory

### Service Injection Errors

If dependency injection fails:
1. Check service constructors match
2. Verify services are in `app/Services/` directory
3. Run `php artisan config:clear`

