# LocationIQ API Setup for Address Autocomplete

## Why LocationIQ?

Bridge Data Output (Zillow) API is designed for **property data lookup**, not address autocomplete. It requires exact addresses and doesn't provide search/autocomplete functionality.

**LocationIQ** is required for address autocomplete functionality. It's free for low-volume usage and provides excellent address search capabilities.

## Quick Setup

### 1. Get LocationIQ API Key (Free)

1. Go to [LocationIQ Dashboard](https://locationiq.com/dashboard)
2. Sign up for a free account (no credit card required)
3. Navigate to "API Keys" section
4. Copy your API key

### 2. Add to .env File

Add this line to your `repro-backend/.env` file:

```env
LOCATIONIQ_API_KEY=your_api_key_here
```

### 3. Restart Backend

```bash
# If using Laravel's built-in server
php artisan serve

# Or restart your web server
```

## Free Tier Limits

- **60 requests/day** - Perfect for development and low-volume usage
- **1 request/second** - Rate limit
- **No credit card required**

## Paid Plans

If you need more requests:
- **Starter**: $99/month - 10,000 requests/day
- **Business**: $299/month - 100,000 requests/day
- **Enterprise**: Custom pricing

## How It Works

- **Address Autocomplete**: Uses LocationIQ (always)
- **Property Details**: Uses Zillow (if configured) for property enrichment after address selection
- **Provider Setting**: Controls which service is used for property data enrichment, not autocomplete

## Testing

After setup, test the address autocomplete:
1. Go to "Book a Shoot" page
2. Type an address (e.g., "10 monroe")
3. You should see address suggestions appear

## Troubleshooting

If autocomplete still doesn't work:

1. **Check API Key**: Verify `LOCATIONIQ_API_KEY` is set in `.env`
2. **Check Logs**: Look at `storage/logs/laravel.log` for errors
3. **Test API Key**: 
   ```bash
   curl "https://us1.locationiq.com/v1/autocomplete?key=YOUR_KEY&q=10%20monroe&countrycodes=us&limit=5"
   ```
4. **Verify Key**: Make sure the key is active in LocationIQ dashboard

## Alternative: Use LocationIQ as Primary Provider

If you want to use LocationIQ for everything (not just autocomplete), you can change the provider in Settings > Integrations > Address Autocomplete.


