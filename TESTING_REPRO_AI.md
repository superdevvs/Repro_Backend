# Testing Robbie (Rule-Based Chat)

This guide shows you how to test the rule-based Robbie system.

## 🚀 Quick Start

**Fastest way to test (no API/auth needed):**
```bash
cd repro-backend
php artisan migrate  # Run migration first
php test-ai-quick.php
```

**Test via API (with authentication):**
1. Get auth token: `curl -X POST http://localhost:8000/api/login -d '{"email":"...","password":"..."}'`
2. Run test script: `./test-repro-ai.sh YOUR_TOKEN` (Linux/Mac) or `.\test-repro-ai.ps1 -Token YOUR_TOKEN` (Windows)

**Test manually with cURL:**
```bash
TOKEN="your-token"
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "I want to book a shoot"}'
```

## 1. Setup

### Run the migration

```bash
cd repro-backend
php artisan migrate
```

This adds the `intent`, `step`, `state_data`, and `engine` fields to `ai_chat_sessions`.

### Verify routes

The AI chat endpoints should be available at:
- `POST /api/ai/chat` - Send a message
- `GET /api/ai/sessions` - List chat sessions
- `GET /api/ai/sessions/{id}` - Get session messages

### Quick PHP Test (No API needed)

For the fastest test without setting up API/auth:

```bash
cd repro-backend
php test-ai-quick.php
```

This will:
- Use the first user in your database
- Create a test session
- Run through the book shoot flow
- Show you the responses

**Note:** Make sure you have at least one user and one service in your database.

## 2. Testing with cURL

### Get an auth token first

```bash
# Login to get token
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "your@email.com",
    "password": "yourpassword"
  }'

# Save the token from response
TOKEN="your-token-here"
```

### Test 1: Start a new conversation (Book a shoot)

```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "message": "I want to book a shoot"
  }'
```

**Expected response:**
```json
{
  "sessionId": "1",
  "messages": [
    {
      "id": "1",
      "sender": "user",
      "content": "I want to book a shoot",
      "createdAt": "2025-12-16T10:00:00.000000Z"
    },
    {
      "id": "2",
      "sender": "assistant",
      "content": "Sure, let's book a new shoot. Which property is this for?",
      "createdAt": "2025-12-16T10:00:01.000000Z"
    }
  ],
  "meta": {
    "suggestions": [
      "123 Main St, City, State",
      "456 Oak Ave, City, State",
      "Enter new address"
    ],
    "actions": []
  }
}
```

### Test 2: Continue the flow (select property)

```bash
# Use the sessionId from previous response
SESSION_ID="1"

curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "sessionId": "'$SESSION_ID'",
    "message": "123 Main St, City, State"
  }'
```

**Expected:** Asks for date

### Test 3: Provide date

```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "sessionId": "'$SESSION_ID'",
    "message": "Tomorrow"
  }'
```

**Expected:** Asks for time

### Test 4: Provide time

```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "sessionId": "'$SESSION_ID'",
    "message": "Morning"
  }'
```

**Expected:** Asks for services

### Test 5: Provide services

```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "sessionId": "'$SESSION_ID'",
    "message": "Photos only"
  }'
```

**Expected:** Shows confirmation summary

### Test 6: Confirm booking

```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "sessionId": "'$SESSION_ID'",
    "message": "Yes, book it"
  }'
```

**Expected:** Creates shoot and shows success message with actions

## 3. Testing with Context (Button Clicks)

You can simulate button clicks by passing context:

```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "sessionId": "'$SESSION_ID'",
    "message": "Book a new shoot",
    "context": {
      "intent": "book_shoot",
      "propertyAddress": "123 Main St",
      "propertyCity": "San Francisco",
      "propertyState": "CA",
      "propertyZip": "94102"
    }
  }'
```

## 4. Testing Other Flows

### Check Availability

```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "message": "Check photographer availability",
    "context": {
      "intent": "availability"
    }
  }'
```

### View Client Stats

```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "message": "Show me client stats",
    "context": {
      "intent": "client_stats"
    }
  }'
```

### Accounting Summary

```bash
curl -X POST http://localhost:8000/api/ai/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "message": "Show accounting for this month",
    "context": {
      "intent": "accounting"
    }
  }'
```

## 5. Testing with Postman

1. **Create a new request**
   - Method: `POST`
   - URL: `http://localhost:8000/api/ai/chat`
   - Headers:
     - `Content-Type: application/json`
     - `Authorization: Bearer YOUR_TOKEN`

2. **Body (raw JSON):**
```json
{
  "message": "I want to book a shoot",
  "context": {
    "intent": "book_shoot"
  }
}
```

3. **Save sessionId** from response and use it in subsequent requests

## 6. Testing with PHPUnit (Optional)

Create a test file:

```php
// tests/Feature/ReproAiTest.php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReproAiTest extends TestCase
{
    use RefreshDatabase;

    public function test_book_shoot_flow()
    {
        $user = User::factory()->create();
        $service = Service::factory()->create(['name' => 'Photos', 'price' => 100]);

        // Start conversation
        $response = $this->actingAs($user)
            ->postJson('/api/ai/chat', [
                'message' => 'I want to book a shoot',
                'context' => ['intent' => 'book_shoot']
            ]);

        $response->assertStatus(201);
        $sessionId = $response->json('sessionId');

        // Continue flow
        $response = $this->actingAs($user)
            ->postJson('/api/ai/chat', [
                'sessionId' => $sessionId,
                'message' => '123 Main St, San Francisco, CA',
                'context' => [
                    'propertyAddress' => '123 Main St',
                    'propertyCity' => 'San Francisco',
                    'propertyState' => 'CA',
                    'propertyZip' => '94102'
                ]
            ]);

        $response->assertStatus(201);
        $this->assertArrayHasKey('meta', $response->json());
        $this->assertArrayHasKey('suggestions', $response->json('meta'));
    }
}
```

Run tests:
```bash
php artisan test --filter ReproAiTest
```

## 7. Debugging

### Check session state in database

```bash
php artisan tinker
```

```php
$session = App\Models\AiChatSession::latest()->first();
$session->intent;    // Should be 'book_shoot'
$session->step;      // Current step
$session->state_data; // Collected data
$session->engine;    // Should be 'rules'
```

### View all messages

```php
$session->messages()->get()->map(fn($m) => [
    'sender' => $m->sender,
    'content' => $m->content,
    'created_at' => $m->created_at
]);
```

### Check logs

```bash
tail -f storage/logs/laravel.log
```

## 8. Common Issues

### Issue: "No services selected"
**Solution:** Make sure you have services in the database:
```bash
php artisan tinker
Service::create(['name' => 'Photos', 'price' => 100, 'category_id' => 1]);
```

### Issue: "Property not found"
**Solution:** The flow uses recent shoots to suggest properties. Create a test shoot first, or the flow will accept typed addresses.

### Issue: Session not persisting
**Solution:** Check that the migration ran successfully:
```bash
php artisan migrate:status
```

## 9. Frontend Integration

The frontend should:
1. Store `sessionId` from response
2. Display `messages` array
3. Show `meta.suggestions` as buttons
4. Handle `meta.actions` (e.g., `open_shoot`)

Example frontend code:
```typescript
const response = await fetch('/api/ai/chat', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${token}`
  },
  body: JSON.stringify({
    sessionId: currentSessionId,
    message: userMessage,
    context: { intent: 'book_shoot' }
  })
});

const data = await response.json();
// data.sessionId - use for next request
// data.messages - render in chat UI
// data.meta.suggestions - show as buttons
// data.meta.actions - handle actions (e.g., navigate to shoot)
```

## 10. Quick Test Script

Save this as `test-ai.sh`:

```bash
#!/bin/bash

API_URL="http://localhost:8000/api"
TOKEN="your-token-here"

echo "1. Starting conversation..."
RESPONSE=$(curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"message": "Book a shoot"}')

SESSION_ID=$(echo $RESPONSE | jq -r '.sessionId')
echo "Session ID: $SESSION_ID"

echo -e "\n2. Providing property..."
curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"sessionId\": \"$SESSION_ID\", \"message\": \"123 Main St, SF, CA\"}" | jq

echo -e "\n3. Providing date..."
curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"sessionId\": \"$SESSION_ID\", \"message\": \"Tomorrow\"}" | jq

echo -e "\n4. Providing time..."
curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"sessionId\": \"$SESSION_ID\", \"message\": \"Morning\"}" | jq

echo -e "\n5. Providing services..."
curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"sessionId\": \"$SESSION_ID\", \"message\": \"Photos only\"}" | jq

echo -e "\n6. Confirming..."
curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"sessionId\": \"$SESSION_ID\", \"message\": \"Yes, book it\"}" | jq
```

Make it executable and run:
```bash
chmod +x test-ai.sh
./test-ai.sh
```

