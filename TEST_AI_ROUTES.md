# Testing AI Chat Routes

Since other endpoints work but AI chat doesn't, the issue is likely:

1. **Role middleware blocking** - Your user role doesn't match `client,admin,superadmin`
2. **Controller instantiation failure** - Dependency injection issue

## Test Steps

### 1. Test Authentication
```bash
# Get your auth token from browser localStorage, then:
curl -X GET http://localhost:8000/api/ai/test-auth \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Expected:** JSON showing your role and whether you have access

### 2. Test Health Endpoint (No Auth)
```bash
curl http://localhost:8000/api/ai/health
```

**Expected:** `{"status":"ok","service":"Robbie AI Chat",...}`

### 3. Test Chat Endpoint
```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"message":"test"}'
```

**Check response:**
- **401** = Not authenticated
- **403** = Role mismatch (check your role vs required roles)
- **500** = Server error (check logs)
- **200** = Success!

### 4. Check Your User Role

In browser console or via API:
```javascript
// Check what role your user has
// The test-auth endpoint will show this
```

## Common Issues

### Issue: 403 Forbidden
**Cause:** Your user role is not in `['client', 'admin', 'superadmin']`

**Check:**
- What is your user's role? (check `/api/ai/test-auth`)
- Does it match one of: `client`, `admin`, `superadmin`?

**Fix:**
- Update your user's role in the database
- OR modify the route to include your role

### Issue: 500 Server Error
**Cause:** Controller can't be instantiated or flow error

**Check:**
- `storage/logs/laravel.log` for "AI Chat fatal error"
- Run `php test-ai-endpoint.php` to test dependencies

### Issue: Network Error (ERR_NETWORK)
**Cause:** Request never reaches backend

**Check:**
- Is backend running? (`http://localhost:8000/api/ping`)
- Is the URL correct? (check browser console)
- CORS issue? (check browser console for CORS errors)
