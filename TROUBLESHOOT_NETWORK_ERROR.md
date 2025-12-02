# Troubleshooting Network Error in AI Chat

## Quick Diagnosis Steps

### 1. Verify Backend Server is Running
```bash
# Check if Laravel server is running
# In your backend terminal, you should see:
# "Laravel development server started: http://127.0.0.1:8000"

# Test the server is responding:
curl http://localhost:8000/api/ping
# Should return: {"status":"success","timestamp":"...","message":"API is working V1"}

# Test AI health endpoint:
curl http://localhost:8000/api/ai/health
# Should return: {"status":"ok","service":"Robbie AI Chat","timestamp":"...","routes_loaded":true}
```

### 2. Check Browser Console (F12)
Open DevTools (F12) and check:

**Console Tab:**
- Look for any red error messages
- Check for CORS errors
- Look for the detailed network error logs I added

**Network Tab:**
1. Try sending a message
2. Find the failed request to `/api/ai/chat`
3. Click on it and check:
   - **Status**: What HTTP status code? (0 = network error, 401 = auth, 500 = server error)
   - **Headers**: Is the Authorization header present?
   - **Response**: Any error message?
   - **Request URL**: Is it pointing to the correct backend?

### 3. Verify API URL Configuration

Check `repro-frontend/.env` or `repro-frontend/.env.local`:
```bash
VITE_API_URL=http://localhost:8000
# OR
VITE_API_PORT=8000
```

The frontend should be pointing to where your Laravel server is running.

### 4. Check Backend Logs
```bash
# Watch logs in real-time
tail -f storage/logs/laravel.log

# Or check last 50 lines
tail -n 50 storage/logs/laravel.log
```

Look for:
- "AI Chat request received" (means request reached backend)
- Any error messages
- Stack traces

### 5. Test Authentication
The AI chat endpoint requires authentication. Verify:
1. You're logged in to the frontend
2. Check browser localStorage:
   - Open DevTools → Application → Local Storage
   - Look for `authToken`, `token`, or `access_token`
   - Should have a value

### 6. Test with curl (if you have a token)
```bash
# Get your token from browser localStorage, then:
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -d '{"message":"test"}'
```

## Common Issues & Solutions

### Issue: "Network Error" (ERR_NETWORK)
**Cause:** Request never reaches the server
**Solutions:**
1. Backend server not running → Start it: `php artisan serve`
2. Wrong API URL → Check `VITE_API_URL` in frontend `.env`
3. Firewall blocking → Check Windows Firewall
4. Port conflict → Try different port: `php artisan serve --port=8001`

### Issue: CORS Error
**Cause:** Browser blocking cross-origin request
**Solutions:**
1. Check `config/cors.php` includes your frontend origin
2. Clear browser cache
3. Try accessing from same origin (if possible)

### Issue: 401 Unauthorized
**Cause:** Missing or invalid auth token
**Solutions:**
1. Log out and log back in
2. Check localStorage for token
3. Verify token is being sent in Authorization header

### Issue: 500 Server Error
**Cause:** Backend code error
**Solutions:**
1. Check `storage/logs/laravel.log` for error details
2. Run `php artisan optimize:clear`
3. Check if migrations are up to date: `php artisan migrate:status`

## What I Added for Debugging

1. **Request Logging**: Every AI chat request is now logged to `storage/logs/laravel.log`
2. **Health Check Endpoint**: `/api/ai/health` to test if routes are loaded
3. **Better Error Messages**: Frontend shows specific error types
4. **Console Logging**: Network errors are logged to browser console with details

## Next Steps

After checking the above:

1. **Share Browser Console Output**: 
   - F12 → Console tab → Copy any errors
   - F12 → Network tab → Click failed request → Copy details

2. **Share Backend Logs**:
   ```bash
   tail -n 100 storage/logs/laravel.log
   ```

3. **Verify Server Status**:
   - Is `php artisan serve` running?
   - What port is it on?
   - Can you access `http://localhost:8000/api/ping` in browser?
