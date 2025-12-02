# Fix for "engine column missing" Error

## Problem
When sending "book new shoot", you got:
```
table `ai_chat_sessions` has no column named `engine`
```

## Solution

### 1. Run the Migration

The migration adds the required columns. Run:

```bash
cd repro-backend
php artisan migrate
```

If you get errors, you can also run:
```bash
php artisan migrate:fresh  # WARNING: This will drop all tables
# OR
php artisan migrate:refresh  # This will rollback and re-run
```

### 2. What Was Fixed

1. **Migration updated** - Changed `enum` to `string` for SQLite compatibility
2. **Safety checks added** - Code now checks if columns exist before using them
3. **Better intent detection** - Now recognizes "book new shoot" properly
4. **Updated suggestions** - Shows all available flows when intent is unclear

### 3. Verify Migration Ran

Check if columns exist:
```bash
php artisan tinker
```

```php
Schema::hasColumn('ai_chat_sessions', 'engine'); // Should return true
Schema::getColumnListing('ai_chat_sessions'); // Should include: intent, step, state_data, engine
```

### 4. Test Again

After migration runs, test with:
```bash
php test-ai-quick.php
```

Or via API:
```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "book new shoot"}'
```

## Available Flows

When you send a message without a clear intent, Robbie will show:

- 📸 **Book a new shoot** - Full booking flow
- 📅 **Manage an existing booking** - View/edit bookings
- 👤 **Check photographer availability** - See available slots
- 📊 **View client stats** - Client statistics
- 💰 **See accounting summary** - Financial summaries

You can trigger any flow by:
1. Clicking a suggestion button (sends `context.intent`)
2. Typing keywords (e.g., "book shoot", "check availability")
3. Sending the exact flow name

