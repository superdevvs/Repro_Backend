# Robbie Testing Script (PowerShell)
# Usage: .\test-repro-ai.ps1 -Token "your-auth-token"

param(
    [Parameter(Mandatory=$true)]
    [string]$Token,
    [string]$ApiUrl = "http://localhost:8000/api"
)

Write-Host "🧪 Testing Robbie Rule-Based Chat" -ForegroundColor Cyan
Write-Host "====================================" -ForegroundColor Cyan
Write-Host ""

$headers = @{
    "Content-Type" = "application/json"
    "Authorization" = "Bearer $Token"
}

# Test 1: Start conversation
Write-Host "Test 1: Starting conversation (Book a shoot)" -ForegroundColor Blue
$body = @{
    message = "I want to book a shoot"
} | ConvertTo-Json

$response = Invoke-RestMethod -Uri "$ApiUrl/ai/chat" -Method Post -Headers $headers -Body $body
$sessionId = $response.sessionId

if (-not $sessionId) {
    Write-Host "❌ Failed to create session" -ForegroundColor Red
    $response | ConvertTo-Json -Depth 10
    exit 1
}

Write-Host "✓ Session created: $sessionId" -ForegroundColor Green
Write-Host $response.messages[-1].content
Write-Host ""

# Test 2: Provide property
Write-Host "Test 2: Providing property address" -ForegroundColor Blue
$body = @{
    sessionId = $sessionId
    message = "123 Main Street, San Francisco, CA 94102"
    context = @{
        propertyAddress = "123 Main Street"
        propertyCity = "San Francisco"
        propertyState = "CA"
        propertyZip = "94102"
    }
} | ConvertTo-Json -Depth 3

$response = Invoke-RestMethod -Uri "$ApiUrl/ai/chat" -Method Post -Headers $headers -Body $body
Write-Host $response.messages[-1].content
Write-Host ""

# Test 3: Provide date
Write-Host "Test 3: Providing date" -ForegroundColor Blue
$body = @{
    sessionId = $sessionId
    message = "Tomorrow"
} | ConvertTo-Json

$response = Invoke-RestMethod -Uri "$ApiUrl/ai/chat" -Method Post -Headers $headers -Body $body
Write-Host $response.messages[-1].content
Write-Host ""

# Test 4: Provide time
Write-Host "Test 4: Providing time" -ForegroundColor Blue
$body = @{
    sessionId = $sessionId
    message = "Morning"
} | ConvertTo-Json

$response = Invoke-RestMethod -Uri "$ApiUrl/ai/chat" -Method Post -Headers $headers -Body $body
Write-Host $response.messages[-1].content
Write-Host ""

# Test 5: Provide services
Write-Host "Test 5: Providing services" -ForegroundColor Blue
$body = @{
    sessionId = $sessionId
    message = "Photos only"
} | ConvertTo-Json

$response = Invoke-RestMethod -Uri "$ApiUrl/ai/chat" -Method Post -Headers $headers -Body $body
Write-Host $response.messages[-1].content
Write-Host ""

# Test 6: Confirm
Write-Host "Test 6: Confirming booking" -ForegroundColor Blue
$body = @{
    sessionId = $sessionId
    message = "Yes, book it"
} | ConvertTo-Json

$response = Invoke-RestMethod -Uri "$ApiUrl/ai/chat" -Method Post -Headers $headers -Body $body
Write-Host $response.messages[-1].content
Write-Host ""

# Check if shoot was created
if ($response.meta.actions -and $response.meta.actions[0].shoot_id) {
    $shootId = $response.meta.actions[0].shoot_id
    Write-Host "✓ Shoot created with ID: $shootId" -ForegroundColor Green
} else {
    Write-Host "⚠ No shoot_id in actions (might be expected if services are missing)" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "✅ All tests completed!" -ForegroundColor Green
Write-Host "Session ID: $sessionId"
Write-Host ""
Write-Host "View session messages:"
Write-Host "Invoke-RestMethod -Uri `"$ApiUrl/ai/sessions/$sessionId`" -Headers @{Authorization=`"Bearer $Token`"}"

