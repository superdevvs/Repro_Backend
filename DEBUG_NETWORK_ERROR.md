# Debugging Network Error in AI Chat

## Issue
Frontend shows "Network Error" when trying to send messages to `/api/ai/chat`.

## Possible Causes

### 1. Backend Server Not Running
**Check:** Is Laravel server running?
```bash
# Check if server is running
# If using php artisan serve:
php artisan serve

# Or check your process manager (supervisor, systemd, etc.)
```

### 2. Backend Server Crashed
**Check:** Look for fatal errors in:
- `storage/logs/laravel.log`
- Server console output
- PHP error logs

**Common causes:**
- Missing dependencies
- Database connection issues
- Fatal PHP errors

### 3. CORS Issues
**Check:** Browser console for CORS errors

**Fix:** Verify `config/cors.php` allows your frontend origin

### 4. Wrong API URL
**Check:** Frontend `API_BASE_URL` in `repro-frontend/src/config/env.ts`

**Verify:** The URL should match where your Laravel server is running (e.g., `http://localhost:8000`)

### 5. Authentication Token Missing/Invalid
**Check:** Browser localStorage for `authToken`, `token`, or `access_token`

**Verify:** Token is being sent in Authorization header

## Quick Tests

### Test 1: Verify Backend is Running
```bash
curl http://localhost:8000/api/ping
# Should return: {"status":"success","message":"pong"}
```

### Test 2: Test AI Endpoint (requires auth token)
```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"message":"test"}'
```

### Test 3: Check Laravel Logs
```bash
tail -f storage/logs/laravel.log
# Then try sending a message from frontend
# Look for errors
```

### Test 4: Verify Dependencies
```bash
php test-ai-endpoint.php
# Should show all dependencies resolved successfully
```

## Code Changes Made

1. **AiChatController**: Removed `ReproAiOrchestrator` dependency (was causing injection failures)
2. **Error Handling**: Added try-catch blocks to prevent fatal errors
3. **Frontend**: Improved error messages to show specific issues

## Next Steps

1. **Restart Backend Server**
   ```bash
   # Stop current server (Ctrl+C)
   # Then restart:
   php artisan serve
   # Or restart your process manager
   ```

2. **Clear All Caches**
   ```bash
   php artisan optimize:clear
   php artisan config:clear
   php artisan route:clear
   php artisan cache:clear
   ```

3. **Check Browser Console**
   - Open DevTools (F12)
   - Go to Console tab
   - Look for specific error messages
   - Go to Network tab to see the actual HTTP request/response

4. **Verify API URL**
   - Check `repro-frontend/src/config/env.ts`
   - Ensure `VITE_API_URL` or default port matches your backend

5. **Check Backend Logs**
   ```bash
   tail -n 100 storage/logs/laravel.log
   ```

## If Still Not Working

Share:
1. Browser console errors (F12 → Console)
2. Network tab details (F12 → Network → click on failed request)
3. Backend log output (`storage/logs/laravel.log`)
4. Confirmation that backend server is running
