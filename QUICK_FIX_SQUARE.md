# Quick Fix: Square Payment Configuration Error

## The Error You're Seeing

"Square Location ID is not configured. Please set VITE_SQUARE_LOCATION_ID in your environment variables."

## Why This Happens

The frontend is trying to load Square payment configuration but can't find it. The component now fetches from the backend, but if the backend isn't configured, it shows this error.

## Quick Fix (3 Steps)

### Step 1: Configure Backend (.env)

Open `repro-backend/.env` and add:

```env
SQUARE_ACCESS_TOKEN=EAAAlwwtMDNzksTtV1dpOEQNqECFUwv_7mAGTsK9VpCgqO5WfAgEN0s9zsyFiLfv
SQUARE_APPLICATION_ID=sandbox-sq0idb-KBncaaZuhXcaX42j5O7zdg
SQUARE_LOCATION_ID=YOUR_LOCATION_ID_HERE
SQUARE_ENVIRONMENT=sandbox
SQUARE_CURRENCY=USD
```

### Step 2: Get Your Location ID

**Option A: Use the setup script (Easiest)**
```powershell
cd repro-backend
.\setup-square.ps1
```
Choose option 1 (Sandbox), then option 3 (Get Location ID)

**Option B: Use the test endpoint**
1. Make sure your Laravel server is running: `php artisan serve`
2. Visit: `http://localhost:8000/api/test/square-locations`
3. Copy one of the Location IDs from the response
4. Add it to `.env` as `SQUARE_LOCATION_ID=your_location_id_here`

### Step 3: Clear Cache and Restart

```bash
cd repro-backend
php artisan config:clear
# Restart your Laravel server
```

## Verify It Works

1. Visit: `http://localhost:8000/api/test/square-connection`
2. You should see a success message with merchant and location info
3. Try the payment dialog again - it should now work!

## Troubleshooting

### Still seeing the error?

1. **Check .env file exists**: Make sure `repro-backend/.env` exists
2. **Check values are set**: Open `.env` and verify all Square variables are there
3. **Clear config cache**: Run `php artisan config:clear`
4. **Restart server**: Stop and restart `php artisan serve`
5. **Check browser console**: Look for any API errors when the payment dialog opens

### Location ID not found?

- Make sure `SQUARE_ACCESS_TOKEN` is set correctly
- Visit `/api/test/square-locations` to see available locations
- Copy the exact Location ID (it's a long string like `LXXXXXXXXXXXXX`)

## What Changed

The frontend now automatically fetches Square configuration from the backend, so you only need to configure the backend `.env` file. The frontend `.env` is optional.

