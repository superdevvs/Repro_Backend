# How to Check Backend Errors

## Quick Check

1. **Check Laravel logs:**
   ```bash
   cd repro-backend
   tail -n 100 storage/logs/laravel.log
   ```

2. **Look for these error patterns:**
   - "AI chat error"
   - "Flow execution error"
   - "Failed to process chat message"
   - Any PHP fatal errors or exceptions

3. **Check if server is running:**
   ```bash
   # In backend terminal, you should see:
   # "Laravel development server started: http://127.0.0.1:8000"
   ```

## Test Endpoints

1. **Health check (no auth):**
   ```
   http://localhost:8000/api/ai/health
   ```
   Should return: `{"status":"ok","service":"Robbie AI Chat",...}`

2. **Test endpoint (with auth):**
   ```
   POST http://localhost:8000/api/ai/test
   ```
   Requires authentication token. Tests basic session creation.

3. **Main chat endpoint:**
   ```
   POST http://localhost:8000/api/ai/chat
   ```
   Requires authentication token and message.

## Common 500 Error Causes

1. **Missing database columns** - Run migrations:
   ```bash
   php artisan migrate
   ```

2. **Dependency injection failure** - Check if all services exist:
   ```bash
   php test-ai-endpoint.php
   ```

3. **Fatal PHP error** - Check logs for syntax errors or missing classes

4. **Memory limit** - Check PHP memory_limit in php.ini

## Share for Debugging

If still getting errors, share:
1. Last 50 lines of `storage/logs/laravel.log`
2. Output of `php test-ai-endpoint.php`
3. Browser console Network tab details for the failed request
