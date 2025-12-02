# Square Payment API Setup Guide

## Overview
This guide will help you configure Square Payments API for your application.

## Required Credentials

You need the following from your Square Developer Dashboard:

1. **Access Token** - Used to authenticate API requests
2. **Location ID** - Identifies which Square location to use
3. **Environment** - Either `sandbox` (for testing) or `production` (for live payments)

## Step 1: Get Your Square Credentials

### For Sandbox (Testing):
1. Go to [Square Developer Dashboard](https://developer.squareup.com/us/en)
2. Sign in to your account
3. Navigate to your application
4. Go to the **Sandbox** tab
5. Copy your **Access Token**
6. Note your **Application ID** (for reference)

### For Production (Live Payments):
1. In the Square Developer Dashboard
2. Go to the **Production** tab
3. Copy your **Production Access Token**
4. Note your **Production Application ID** (for reference)

## Step 2: Get Your Location ID

The Location ID is required for processing payments. You can get it in two ways:

### Method 1: Using the Test Endpoint (Recommended)
1. First, add your access token to `.env`:
   ```env
   SQUARE_ACCESS_TOKEN=your_access_token_here
   SQUARE_ENVIRONMENT=sandbox
   ```

2. Start your Laravel server:
   ```bash
   php artisan serve
   ```

3. Visit this URL in your browser:
   ```
   http://localhost:8000/api/test/square-locations
   ```

4. This will return a list of all locations. Copy the `id` of the location you want to use.

### Method 2: From Square Dashboard
1. Go to [Square Dashboard](https://squareup.com/dashboard)
2. Navigate to **Settings** → **Locations**
3. Click on the location you want to use
4. The Location ID is displayed in the URL or in the location details

## Step 3: Configure Environment Variables

Add these to your `repro-backend/.env` file:

### For Sandbox (Testing):
```env
SQUARE_ACCESS_TOKEN=EAAAlwwtMDNzksTtV1dpOEQNqECFUwv_7mAGTsK9VpCgqO5WfAgEN0s9zsyFiLfv
SQUARE_LOCATION_ID=your_location_id_here
SQUARE_ENVIRONMENT=sandbox
SQUARE_CURRENCY=USD
```

### For Production (Live Payments):
```env
SQUARE_ACCESS_TOKEN=EAAAly-d0wuus8_9xEHnKok37ibsM8W_mE2YpQO63d_-SUZa7T3vjS7DdTGxXHGe
SQUARE_LOCATION_ID=your_production_location_id_here
SQUARE_ENVIRONMENT=production
SQUARE_CURRENCY=USD
```

**Important:** 
- Never commit your `.env` file to version control
- Use sandbox credentials for development and testing
- Only switch to production credentials when ready for live payments

## Step 4: Test the Connection

After configuring your credentials:

1. Clear Laravel config cache:
   ```bash
   php artisan config:clear
   ```

2. Test the connection:
   ```
   http://localhost:8000/api/test/square-connection
   ```

3. You should see a success response with:
   - Merchant information
   - Location details
   - Payment API status

## Step 5: Verify Your Credentials

### Your Current Credentials:

**Sandbox:**
- App ID: `sandbox-sq0idb-KBncaaZuhXcaX42j5O7zdg`
- Access Token: `EAAAlwwtMDNzksTtV1dpOEQNqECFUwv_7mAGTsK9VpCgqO5WfAgEN0s9zsyFiLfv`

**Production:**
- App ID: `sq0idp-VwrHAzcPpOOEPyCQSgn1Dg`
- Access Token: `EAAAly-d0wuus8_9xEHnKok37ibsM8W_mE2YpQO63d_-SUZa7T3vjS7DdTGxXHGe`

### Next Steps:
1. ✅ Add `SQUARE_ACCESS_TOKEN` to `.env` (use sandbox token for testing)
2. ⚠️ **Get your Location ID** using the test endpoint or Square Dashboard
3. ✅ Add `SQUARE_LOCATION_ID` to `.env`
4. ✅ Set `SQUARE_ENVIRONMENT=sandbox` for testing
5. ✅ Test the connection using `/api/test/square-connection`

## Troubleshooting

### Error: "Please pass in token or set the environment variable SQUARE_TOKEN"
- Make sure `SQUARE_ACCESS_TOKEN` is set in your `.env` file
- Run `php artisan config:clear` after updating `.env`
- Restart your Laravel server

### Error: "Location ID is required"
- Get your Location ID using `/api/test/square-locations`
- Add it to `.env` as `SQUARE_LOCATION_ID`

### Error: "Invalid access token"
- Verify your access token is correct
- Make sure you're using the right token for the environment (sandbox vs production)
- Check if the token has expired (tokens can expire, regenerate if needed)

### API Connection Fails
- Check your internet connection
- Verify the Square API is accessible
- Check Laravel logs: `storage/logs/laravel.log`

## Security Notes

- **Never expose your access tokens** in client-side code
- **Use sandbox tokens** for development
- **Rotate tokens** if they're accidentally exposed
- **Use environment variables** - never hardcode credentials
- **Restrict API permissions** in Square Dashboard to minimum required

## Additional Resources

- [Square Developer Documentation](https://developer.squareup.com/docs)
- [Square PHP SDK Documentation](https://github.com/square/square-php-sdk)
- [Square API Reference](https://developer.squareup.com/reference/square)


