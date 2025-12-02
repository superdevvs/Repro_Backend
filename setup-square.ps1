# Square Payment API Setup Script
# This script helps you configure Square payment credentials

Write-Host "=== Square Payment API Setup ===" -ForegroundColor Cyan
Write-Host ""

# Check if .env file exists
if (-not (Test-Path ".env")) {
    Write-Host "❌ .env file not found!" -ForegroundColor Red
    Write-Host "Please create a .env file first by copying .env.example" -ForegroundColor Yellow
    exit 1
}

Write-Host "✓ .env file found" -ForegroundColor Green
Write-Host ""

# Read current .env content
$envContent = Get-Content ".env" -Raw

# Check current Square configuration
$hasAccessToken = $envContent -match "SQUARE_ACCESS_TOKEN=(.+)"
$hasApplicationId = $envContent -match "SQUARE_APPLICATION_ID=(.+)"
$hasLocationId = $envContent -match "SQUARE_LOCATION_ID=(.+)"
$hasEnvironment = $envContent -match "SQUARE_ENVIRONMENT=(.+)"

Write-Host "Current Configuration:" -ForegroundColor Yellow
if ($hasAccessToken) {
    $token = $matches[1].Trim()
    if ($token -and $token -ne "" -and $token -ne "your_access_token_here") {
        Write-Host "  ✓ SQUARE_ACCESS_TOKEN: $($token.Substring(0, [Math]::Min(15, $token.Length)))..." -ForegroundColor Green
    } else {
        Write-Host "  ❌ SQUARE_ACCESS_TOKEN: NOT SET" -ForegroundColor Red
    }
} else {
    Write-Host "  ❌ SQUARE_ACCESS_TOKEN: NOT SET" -ForegroundColor Red
}

if ($hasApplicationId) {
    $appId = $matches[1].Trim()
    if ($appId -and $appId -ne "" -and $appId -ne "your_application_id_here") {
        Write-Host "  ✓ SQUARE_APPLICATION_ID: $($appId.Substring(0, [Math]::Min(20, $appId.Length)))..." -ForegroundColor Green
    } else {
        Write-Host "  ❌ SQUARE_APPLICATION_ID: NOT SET" -ForegroundColor Red
    }
} else {
    Write-Host "  ❌ SQUARE_APPLICATION_ID: NOT SET" -ForegroundColor Red
}

if ($hasLocationId) {
    $locationId = $matches[1].Trim()
    if ($locationId -and $locationId -ne "" -and $locationId -ne "your_location_id_here") {
        Write-Host "  ✓ SQUARE_LOCATION_ID: $locationId" -ForegroundColor Green
    } else {
        Write-Host "  ❌ SQUARE_LOCATION_ID: NOT SET" -ForegroundColor Red
    }
} else {
    Write-Host "  ❌ SQUARE_LOCATION_ID: NOT SET" -ForegroundColor Red
}

if ($hasEnvironment) {
    $env = $matches[1].Trim()
    Write-Host "  ✓ SQUARE_ENVIRONMENT: $env" -ForegroundColor Green
} else {
    Write-Host "  ⚠ SQUARE_ENVIRONMENT: NOT SET (will default to 'sandbox')" -ForegroundColor Yellow
}

Write-Host ""

# Ask user what they want to do
Write-Host "What would you like to do?" -ForegroundColor Cyan
Write-Host "1. Set up Sandbox credentials (for testing)" -ForegroundColor White
Write-Host "2. Set up Production credentials (for live payments)" -ForegroundColor White
Write-Host "3. Just get Location ID (token already set)" -ForegroundColor White
Write-Host "4. Test current configuration" -ForegroundColor White
Write-Host "5. Exit" -ForegroundColor White
Write-Host ""

$choice = Read-Host "Enter your choice (1-5)"

switch ($choice) {
    "1" {
        Write-Host ""
        Write-Host "=== Setting up Sandbox Credentials ===" -ForegroundColor Cyan
        
        # Sandbox credentials
        $sandboxToken = "EAAAlwwtMDNzksTtV1dpOEQNqECFUwv_7mAGTsK9VpCgqO5WfAgEN0s9zsyFiLfv"
        $sandboxAppId = "sandbox-sq0idb-KBncaaZuhXcaX42j5O7zdg"
        
        # Update or add SQUARE_ACCESS_TOKEN
        if ($hasAccessToken) {
            $envContent = $envContent -replace "SQUARE_ACCESS_TOKEN=.+", "SQUARE_ACCESS_TOKEN=$sandboxToken"
        } else {
            $envContent += "`nSQUARE_ACCESS_TOKEN=$sandboxToken"
        }
        
        # Update or add SQUARE_APPLICATION_ID
        if ($hasApplicationId) {
            $envContent = $envContent -replace "SQUARE_APPLICATION_ID=.+", "SQUARE_APPLICATION_ID=$sandboxAppId"
        } else {
            $envContent += "`nSQUARE_APPLICATION_ID=$sandboxAppId"
        }
        
        # Update or add SQUARE_ENVIRONMENT
        if ($hasEnvironment) {
            $envContent = $envContent -replace "SQUARE_ENVIRONMENT=.+", "SQUARE_ENVIRONMENT=sandbox"
        } else {
            $envContent += "`nSQUARE_ENVIRONMENT=sandbox"
        }
        
        # Update or add SQUARE_CURRENCY
        if ($envContent -match "SQUARE_CURRENCY=") {
            $envContent = $envContent -replace "SQUARE_CURRENCY=.+", "SQUARE_CURRENCY=USD"
        } else {
            $envContent += "`nSQUARE_CURRENCY=USD"
        }
        
        # Save .env file
        $envContent | Set-Content ".env"
        
        Write-Host "✓ Sandbox access token configured" -ForegroundColor Green
        Write-Host ""
        Write-Host "⚠ IMPORTANT: You still need to set SQUARE_LOCATION_ID" -ForegroundColor Yellow
        Write-Host "  Run this script again and choose option 3 to get your Location ID" -ForegroundColor Yellow
        Write-Host "  Or visit: http://localhost:8000/api/test/square-locations" -ForegroundColor Yellow
    }
    
    "2" {
        Write-Host ""
        Write-Host "=== Setting up Production Credentials ===" -ForegroundColor Cyan
        Write-Host "⚠ WARNING: Production credentials will process REAL payments!" -ForegroundColor Red
        $confirm = Read-Host "Are you sure you want to use production? (yes/no)"
        
        if ($confirm -ne "yes") {
            Write-Host "Cancelled." -ForegroundColor Yellow
            exit 0
        }
        
        # Production credentials
        $productionToken = "EAAAly-d0wuus8_9xEHnKok37ibsM8W_mE2YpQO63d_-SUZa7T3vjS7DdTGxXHGe"
        $productionAppId = "sq0idp-VwrHAzcPpOOEPyCQSgn1Dg"
        
        # Update or add SQUARE_ACCESS_TOKEN
        if ($hasAccessToken) {
            $envContent = $envContent -replace "SQUARE_ACCESS_TOKEN=.+", "SQUARE_ACCESS_TOKEN=$productionToken"
        } else {
            $envContent += "`nSQUARE_ACCESS_TOKEN=$productionToken"
        }
        
        # Update or add SQUARE_APPLICATION_ID
        if ($hasApplicationId) {
            $envContent = $envContent -replace "SQUARE_APPLICATION_ID=.+", "SQUARE_APPLICATION_ID=$productionAppId"
        } else {
            $envContent += "`nSQUARE_APPLICATION_ID=$productionAppId"
        }
        
        # Update or add SQUARE_ENVIRONMENT
        if ($hasEnvironment) {
            $envContent = $envContent -replace "SQUARE_ENVIRONMENT=.+", "SQUARE_ENVIRONMENT=production"
        } else {
            $envContent += "`nSQUARE_ENVIRONMENT=production"
        }
        
        # Update or add SQUARE_CURRENCY
        if ($envContent -match "SQUARE_CURRENCY=") {
            $envContent = $envContent -replace "SQUARE_CURRENCY=.+", "SQUARE_CURRENCY=USD"
        } else {
            $envContent += "`nSQUARE_CURRENCY=USD"
        }
        
        # Save .env file
        $envContent | Set-Content ".env"
        
        Write-Host "✓ Production access token configured" -ForegroundColor Green
        Write-Host ""
        Write-Host "⚠ IMPORTANT: You still need to set SQUARE_LOCATION_ID" -ForegroundColor Yellow
        Write-Host "  Get it from: https://squareup.com/dashboard/settings/locations" -ForegroundColor Yellow
    }
    
    "3" {
        Write-Host ""
        Write-Host "=== Getting Location ID ===" -ForegroundColor Cyan
        
        # Check if token is set
        if (-not $hasAccessToken) {
            Write-Host "❌ SQUARE_ACCESS_TOKEN must be set first!" -ForegroundColor Red
            Write-Host "Run this script and choose option 1 or 2 to set up credentials" -ForegroundColor Yellow
            exit 1
        }
        
        $token = if ($hasAccessToken) { $matches[1].Trim() } else { "" }
        if (-not $token -or $token -eq "your_access_token_here") {
            Write-Host "❌ SQUARE_ACCESS_TOKEN is not valid!" -ForegroundColor Red
            exit 1
        }
        
        Write-Host "Testing connection to Square API..." -ForegroundColor Yellow
        
        # Check if Laravel server is running
        try {
            $pingResponse = Invoke-RestMethod -Uri "http://localhost:8000/api/ping" -Method GET -TimeoutSec 5 -ErrorAction Stop
            Write-Host "✓ Laravel server is running" -ForegroundColor Green
        } catch {
            Write-Host "❌ Laravel server is not running" -ForegroundColor Red
            Write-Host "Please start the server with: php artisan serve" -ForegroundColor Yellow
            exit 1
        }
        
        Write-Host ""
        Write-Host "Fetching locations from Square API..." -ForegroundColor Yellow
        
        try {
            $locationsResponse = Invoke-RestMethod -Uri "http://localhost:8000/api/test/square-locations" -Method GET -TimeoutSec 10
            
            if ($locationsResponse.success) {
                Write-Host ""
                Write-Host "✓ Found $($locationsResponse.count) location(s):" -ForegroundColor Green
                Write-Host ""
                
                foreach ($location in $locationsResponse.locations) {
                    Write-Host "  Location: $($location.name)" -ForegroundColor Cyan
                    Write-Host "    ID: $($location.id)" -ForegroundColor White
                    if ($location.address) {
                        Write-Host "    Address: $($location.address.address_line_1), $($location.address.locality)" -ForegroundColor Gray
                    }
                    Write-Host ""
                }
                
                $locationId = Read-Host "Enter the Location ID you want to use"
                
                if ($locationId) {
                    # Update .env with location ID
                    if ($hasLocationId) {
                        $envContent = $envContent -replace "SQUARE_LOCATION_ID=.+", "SQUARE_LOCATION_ID=$locationId"
                    } else {
                        $envContent += "`nSQUARE_LOCATION_ID=$locationId"
                    }
                    
                    $envContent | Set-Content ".env"
                    Write-Host "✓ Location ID configured!" -ForegroundColor Green
                }
            } else {
                Write-Host "❌ Failed to retrieve locations: $($locationsResponse.message)" -ForegroundColor Red
            }
        } catch {
            Write-Host "❌ Error connecting to Square API: $($_.Exception.Message)" -ForegroundColor Red
            Write-Host "Make sure your access token is correct and the server is running" -ForegroundColor Yellow
        }
    }
    
    "4" {
        Write-Host ""
        Write-Host "=== Testing Square Configuration ===" -ForegroundColor Cyan
        
        # Check if Laravel server is running
        try {
            $pingResponse = Invoke-RestMethod -Uri "http://localhost:8000/api/ping" -Method GET -TimeoutSec 5 -ErrorAction Stop
            Write-Host "✓ Laravel server is running" -ForegroundColor Green
        } catch {
            Write-Host "❌ Laravel server is not running" -ForegroundColor Red
            Write-Host "Please start the server with: php artisan serve" -ForegroundColor Yellow
            exit 1
        }
        
        Write-Host ""
        Write-Host "Testing Square API connection..." -ForegroundColor Yellow
        
        try {
            $testResponse = Invoke-RestMethod -Uri "http://localhost:8000/api/test/square-connection" -Method GET -TimeoutSec 10
            
            if ($testResponse.success) {
                Write-Host ""
                Write-Host "✅ Square API connection successful!" -ForegroundColor Green
                Write-Host ""
                Write-Host "Merchant Info:" -ForegroundColor Cyan
                Write-Host "  Business: $($testResponse.merchant.business_name)" -ForegroundColor White
                Write-Host "  Country: $($testResponse.merchant.country)" -ForegroundColor White
                Write-Host "  Currency: $($testResponse.merchant.currency)" -ForegroundColor White
                Write-Host ""
                Write-Host "Location Info:" -ForegroundColor Cyan
                Write-Host "  Name: $($testResponse.location.name)" -ForegroundColor White
                Write-Host "  ID: $($testResponse.location.id)" -ForegroundColor White
                Write-Host ""
                Write-Host "✅ All tests passed! Square payments are ready to use." -ForegroundColor Green
            } else {
                Write-Host ""
                Write-Host "❌ Configuration test failed:" -ForegroundColor Red
                Write-Host "  $($testResponse.message)" -ForegroundColor Red
                if ($testResponse.config) {
                    Write-Host ""
                    Write-Host "Current config:" -ForegroundColor Yellow
                    Write-Host "  Access Token: $($testResponse.config.access_token)" -ForegroundColor White
                    Write-Host "  Location ID: $($testResponse.config.location_id)" -ForegroundColor White
                    Write-Host "  Environment: $($testResponse.config.environment)" -ForegroundColor White
                }
            }
        } catch {
            Write-Host ""
            Write-Host "❌ Error testing configuration: $($_.Exception.Message)" -ForegroundColor Red
        }
    }
    
    "5" {
        Write-Host "Exiting..." -ForegroundColor Yellow
        exit 0
    }
    
    default {
        Write-Host "Invalid choice. Exiting..." -ForegroundColor Red
        exit 1
    }
}

Write-Host ""
Write-Host "=== Next Steps ===" -ForegroundColor Cyan
Write-Host "1. Clear Laravel config cache: php artisan config:clear" -ForegroundColor White
Write-Host "2. Restart your Laravel server if it's running" -ForegroundColor White
Write-Host "3. Test the connection: http://localhost:8000/api/test/square-connection" -ForegroundColor White
Write-Host ""


