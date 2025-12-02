# MightyCall SMS Integration - Setup Guide

## Overview
This document describes the MightyCall SMS integration that has been implemented and how to configure it.

## What Was Fixed

### 1. SMS Sending
- **Fixed API endpoint**: Updated from `public-api.mightycall.com` to `ccapi.mightycall.com/v4`
- **Correct endpoint**: `POST /contactcenter/message/send`
- **Request format**: 
  ```json
  {
    "from": "+12028681663",
    "to": ["+15551234567"],
    "message": "Your message text here"
  }
  ```
- **Authentication**: Uses `X-API-Key` header with the number-specific key
- **Phone number formatting**: Automatically formats to E.164 format (+1XXXXXXXXXX)

### 2. Conversation Fetching
- **New method**: `fetchConversations()` in `MightyCallSmsProvider`
- **Endpoint**: `GET /contactcenter/message`
- **Supports filtering**: by phone number, limit, offset

### 3. History Sync Command
- **Command**: `php artisan mightycall:sync-history`
- **Options**:
  - `--number=ID`: Sync specific SMS number
  - `--limit=100`: Maximum messages to fetch per number
- **Features**:
  - Fetches messages from MightyCall API
  - Creates/updates contacts and threads
  - Prevents duplicate messages
  - Handles both inbound and outbound messages

### 4. Configuration
- Added MightyCall config to `config/services.php`
- API key can be set via `MIGHTYCALL_API_KEY` env variable
- Base URL can be overridden via `MIGHTYCALL_BASE_URL` env variable

## Setup Instructions

### Step 1: Configure SMS Numbers

You need to add your SMS numbers with their MightyCall keys. You can do this via the API or directly in the database.

#### Via API (Recommended)
Send a POST request to `/api/messaging/settings/sms`:

```json
{
  "numbers": [
    {
      "phone_number": "(202) 868-1663",
      "label": "Main Phone",
      "mighty_call_key": "60db9a3d9930",
      "is_default": true
    },
    {
      "phone_number": "(202) 780-3332",
      "label": "Editor Account",
      "mighty_call_key": "3847a1cf2d34",
      "is_default": false
    },
    {
      "phone_number": "(888) 656-7627",
      "label": "Dashboard Texting",
      "mighty_call_key": "21ebcff6ba39",
      "is_default": false
    }
  ]
}
```

#### Via Database
Insert records into the `sms_numbers` table:

```sql
INSERT INTO sms_numbers (phone_number, label, mighty_call_key, is_default, created_at, updated_at) VALUES
('(202) 868-1663', 'Main Phone', '60db9a3d9930', 1, NOW(), NOW()),
('(202) 780-3332', 'Editor Account', '3847a1cf2d34', 0, NOW(), NOW()),
('(888) 656-7627', 'Dashboard Texting', '21ebcff6ba39', 0, NOW(), NOW());
```

### Step 2: Sync Existing Conversations

Run the sync command to import existing conversations from MightyCall:

```bash
php artisan mightycall:sync-history
```

To sync a specific number:
```bash
php artisan mightycall:sync-history --number=1
```

To limit the number of messages:
```bash
php artisan mightycall:sync-history --limit=50
```

### Step 3: Test SMS Sending

Send a test SMS via the API:

```bash
POST /api/messaging/sms/send
Authorization: Bearer {your_token}
Content-Type: application/json

{
  "to": "+15551234567",
  "body_text": "Test message",
  "sms_number_id": 1  // Optional, uses default if not specified
}
```

## API Endpoints

### SMS Threads
- `GET /api/messaging/sms/threads` - List all SMS conversation threads
- `GET /api/messaging/sms/threads/{id}` - Get specific thread with messages
- `POST /api/messaging/sms/threads/{id}/messages` - Send message to thread
- `POST /api/messaging/sms/threads/{id}/mark-read` - Mark thread as read

### SMS Sending
- `POST /api/messaging/sms/send` - Send a new SMS message

### Settings
- `GET /api/messaging/settings/sms` - Get SMS number configurations
- `POST /api/messaging/settings/sms` - Save SMS number configurations

## MightyCall API Keys

**Main API Key**: `a2ef1a6d-842a-4848-9777-0372d5fe5de0`

**Number-Specific Keys**:
- Main phone (202) 868-1663: `60db9a3d9930`
- Editor account (202) 780-3332: `3847a1cf2d34`
- Dashboard texting (888) 656-7627: `21ebcff6ba39`

## Error Handling

The integration includes comprehensive error handling:
- Failed SMS sends are logged and marked as `FAILED` status
- API errors are logged with full details
- Invalid phone numbers are automatically formatted
- Missing configurations are logged as warnings

## Files Modified

1. `app/Services/Messaging/Providers/MightyCallSmsProvider.php`
   - Updated API endpoint
   - Added conversation fetching
   - Added phone number formatting

2. `app/Services/Messaging/MessagingService.php`
   - Improved error handling for SMS sending

3. `app/Console/Commands/SyncMightyCallHistory.php`
   - Complete implementation of history sync

4. `config/services.php`
   - Added MightyCall configuration

## Troubleshooting

### SMS Not Sending
1. Check that the SMS number has a `mighty_call_key` set
2. Verify the phone number format (should be E.164: +1XXXXXXXXXX)
3. Check logs for API errors: `storage/logs/laravel.log`
4. Verify the MightyCall key is correct for that number

### Conversations Not Appearing
1. Run the sync command: `php artisan mightycall:sync-history`
2. Check that the number has a valid `mighty_call_key`
3. Verify API access with MightyCall
4. Check logs for sync errors

### API Errors
- Check the response in `storage/logs/laravel.log`
- Verify the API key is correct
- Ensure the phone number format matches MightyCall's requirements
- Check MightyCall API documentation for any changes

## Next Steps

1. Set up a scheduled job to periodically sync conversations:
   ```php
   // In app/Console/Kernel.php
   $schedule->command('mightycall:sync-history')->hourly();
   ```

2. Consider adding webhook support for real-time message delivery (if MightyCall supports it)

3. Add rate limiting if needed for high-volume sending


