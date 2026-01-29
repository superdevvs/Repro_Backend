# Performance Issues & Error Prevention Guide

## Summary of Issues Found

### Issue 1: 500 Internal Server Error - Missing Relationship
**Error:** `Trying to get property 'location' on non-object` or similar relationship errors

**Root Cause:**
- Code in `UserController::getSharedAccountData()` was trying to access `$shoot->location` relationship
- The `Shoot` model does NOT have a `location()` relationship defined
- This caused a fatal error when processing user data

**Location:** `app/Http/Controllers/Admin/UserController.php` line ~289

**Fix Applied:**
- Removed the non-existent `location` relationship access
- Changed to group shoots by address/city/state directly from the shoot model attributes

### Issue 2: 500 Internal Server Error - Date/Time Parsing
**Error:** `DateMalformedStringException: Failed to parse time string (2025-12-04 14:00 PM)`

**Root Cause:**
- Time strings in database had format like "14:00 PM" (24-hour format with AM/PM suffix)
- Carbon couldn't parse this malformed format
- This caused errors in `DashboardController::combineDateAndTime()`

**Location:** `app/Http/Controllers/API/DashboardController.php` line ~367

**Fix Applied:**
- Enhanced `normalizeTimeString()` to detect and remove AM/PM when hour >= 13
- Added better error handling with fallbacks
- Added logging for debugging

### Issue 3: Performance Issue - N+1 Query Problem
**Error:** Account data taking 2+ seconds to load

**Root Cause:**
- `UserController::index()` was calling `User::all()->map()` 
- For EACH user, it was:
  - Querying AccountLink table (2 queries per user)
  - Querying Shoot counts
  - Querying Payment history
  - Grouping properties
- For 12 users = 50+ database queries!

**Location:** `app/Http/Controllers/Admin/UserController.php` line ~22

**Fix Applied:**
- Pre-load all AccountLinks in 1 query with eager loading
- Pre-load all Shoot counts in 1 aggregated query
- Pre-load all total spent in 1 aggregated query
- Reduced from 50+ queries to ~4 queries
- Performance: 2000ms → 291ms (85% faster)

---

## How to Prevent These Issues in the Future

### 1. Always Check Relationships Before Using Them

**❌ BAD:**
```php
$shoot->location->fullAddress  // Assumes relationship exists
```

**✅ GOOD:**
```php
// Option 1: Check if relationship exists in model
if (method_exists($shoot, 'location')) {
    $address = $shoot->location->fullAddress ?? null;
}

// Option 2: Use model attributes directly
$address = $shoot->address ?? null;

// Option 3: Use optional() helper
$address = optional($shoot->location)->fullAddress ?? $shoot->address;
```

**Prevention Checklist:**
- [ ] Check the model file for relationship definitions before using them
- [ ] Use `php artisan tinker` to test: `$model->relationshipName`
- [ ] Always provide fallbacks when accessing relationships
- [ ] Use `optional()` helper for nullable relationships

### 2. Always Validate Data Formats Before Parsing

**❌ BAD:**
```php
Carbon::parse($date . ' ' . $time);  // Assumes format is correct
```

**✅ GOOD:**
```php
try {
    $normalizedTime = $this->normalizeTimeString($time);
    $dateTime = Carbon::parse($date . ' ' . $normalizedTime);
} catch (\Exception $e) {
    \Log::warning('Date parsing failed', ['date' => $date, 'time' => $time]);
    $dateTime = Carbon::parse($date . ' 09:00'); // Fallback
}
```

**Prevention Checklist:**
- [ ] Always normalize/validate data before parsing
- [ ] Use try-catch blocks around date/time parsing
- [ ] Provide sensible fallbacks
- [ ] Log parsing failures for debugging

### 3. Always Use Eager Loading to Prevent N+1 Queries

**❌ BAD (N+1 Problem):**
```php
$users = User::all();
$users->map(function($user) {
    // This runs a query for EACH user!
    $user->shoots()->count();
    $user->payments()->sum('amount');
});
// Result: 1 + (N * 2) queries = 25 queries for 12 users
```

**✅ GOOD (Eager Loading):**
```php
// Load all data in batch queries
$users = User::all();
$userIds = $users->pluck('id');

// Single query for all counts
$shootCounts = Shoot::whereIn('client_id', $userIds)
    ->selectRaw('client_id, COUNT(*) as count')
    ->groupBy('client_id')
    ->pluck('count', 'client_id');

// Single query for all totals
$totals = Shoot::whereIn('client_id', $userIds)
    ->selectRaw('client_id, SUM(total_quote) as total')
    ->groupBy('client_id')
    ->pluck('total', 'client_id');

// Map with pre-loaded data
$users->map(function($user) use ($shootCounts, $totals) {
    $user->shoot_count = $shootCounts->get($user->id, 0);
    $user->total_spent = $totals->get($user->id, 0);
});
// Result: 3 queries total (1 for users, 1 for counts, 1 for totals)
```

**Prevention Checklist:**
- [ ] Use `with()` for eager loading relationships
- [ ] Use `whereIn()` with `groupBy()` for aggregations
- [ ] Use `selectRaw()` with aggregations instead of counting in loops
- [ ] Test query count: `DB::enableQueryLog()` then `DB::getQueryLog()`
- [ ] Use Laravel Debugbar to monitor queries in development

### 4. Use Database Query Monitoring

**Add to your development workflow:**

```php
// In Controller or Service
\DB::enableQueryLog();

// Your code here
$users = User::all()->map(...);

// Check query count
$queries = \DB::getQueryLog();
\Log::info('Query count: ' . count($queries));
\Log::info('Queries:', $queries);
```

**Or use Laravel Debugbar:**
```bash
composer require barryvdh/laravel-debugbar --dev
```

### 5. Code Review Checklist

Before merging code that loads data, check:

- [ ] **Relationships:** Are all relationships used actually defined in the model?
- [ ] **Eager Loading:** Are relationships eager loaded with `with()`?
- [ ] **N+1 Queries:** Are there any loops that query the database?
- [ ] **Data Validation:** Is data validated/normalized before parsing?
- [ ] **Error Handling:** Are there try-catch blocks for risky operations?
- [ ] **Performance:** Has the endpoint been tested with realistic data volumes?

### 6. Testing Best Practices

**Unit Tests:**
```php
public function test_user_index_does_not_have_n_plus_one_queries()
{
    User::factory()->count(10)->create();
    
    \DB::enableQueryLog();
    $response = $this->getJson('/api/admin/users');
    $queries = \DB::getQueryLog();
    
    // Should be < 10 queries, not 50+
    $this->assertLessThan(10, count($queries));
    $response->assertStatus(200);
}
```

**Performance Tests:**
```php
public function test_user_index_loads_quickly()
{
    User::factory()->count(100)->create();
    
    $start = microtime(true);
    $response = $this->getJson('/api/admin/users');
    $duration = microtime(true) - $start;
    
    // Should load in < 500ms even with 100 users
    $this->assertLessThan(0.5, $duration);
    $response->assertStatus(200);
}
```

### 7. Monitoring in Production

**Add query logging for slow endpoints:**
```php
public function index(Request $request)
{
    $start = microtime(true);
    \DB::enableQueryLog();
    
    // Your code here
    $users = User::all()->map(...);
    
    $duration = microtime(true) - $start;
    $queryCount = count(\DB::getQueryLog());
    
    if ($duration > 1.0 || $queryCount > 20) {
        \Log::warning('Slow endpoint detected', [
            'endpoint' => 'admin/users',
            'duration' => $duration,
            'query_count' => $queryCount,
        ]);
    }
    
    return response()->json(['users' => $users]);
}
```

---

## Quick Reference: Common Patterns

### Pattern 1: Loading Related Data
```php
// ❌ BAD
$users->each(function($user) {
    $user->shoots_count = $user->shoots()->count(); // N queries
});

// ✅ GOOD
$shootCounts = Shoot::whereIn('client_id', $userIds)
    ->selectRaw('client_id, COUNT(*) as count')
    ->groupBy('client_id')
    ->pluck('count', 'client_id');
    
$users->each(function($user) use ($shootCounts) {
    $user->shoots_count = $shootCounts->get($user->id, 0);
});
```

### Pattern 2: Accessing Relationships
```php
// ❌ BAD
$address = $shoot->location->fullAddress; // May not exist

// ✅ GOOD
$address = optional($shoot->location)->fullAddress 
    ?? ($shoot->address . ', ' . $shoot->city);
```

### Pattern 3: Parsing Dates/Times
```php
// ❌ BAD
$dateTime = Carbon::parse($date . ' ' . $time);

// ✅ GOOD
try {
    $normalized = $this->normalizeTimeString($time);
    $dateTime = Carbon::parse($date . ' ' . $normalized);
} catch (\Exception $e) {
    \Log::warning('Date parse failed', ['date' => $date, 'time' => $time]);
    $dateTime = Carbon::parse($date . ' 09:00'); // Fallback
}
```

---

## Tools & Resources

1. **Laravel Debugbar** - Monitor queries in development
   ```bash
   composer require barryvdh/laravel-debugbar --dev
   ```

2. **Laravel Telescope** - Monitor all queries, requests, exceptions
   ```bash
   composer require laravel/telescope --dev
   php artisan telescope:install
   ```

3. **Query Monitoring Middleware** - Add to detect slow queries
   ```php
   // In AppServiceProvider
   if (app()->environment('local')) {
       \DB::listen(function ($query) {
           if ($query->time > 100) { // > 100ms
               \Log::warning('Slow query', [
                   'sql' => $query->sql,
                   'time' => $query->time,
               ]);
           }
       });
   }
   ```

---

## Summary

**Key Takeaways:**
1. ✅ Always verify relationships exist before using them
2. ✅ Always validate/normalize data before parsing
3. ✅ Always use eager loading and batch queries to prevent N+1
4. ✅ Always add error handling with fallbacks
5. ✅ Always monitor query counts and performance
6. ✅ Always test with realistic data volumes

**Performance Target:**
- List endpoints should load in < 500ms
- Query count should be < 10 for list endpoints
- Use aggregation queries instead of loops

**Error Prevention:**
- Use `optional()` for nullable relationships
- Use try-catch for risky operations
- Provide sensible fallbacks
- Log errors for debugging





