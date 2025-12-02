# Square Web Payments SDK Integration Guide

This guide explains how to use the new Square Web Payments SDK integration for embedded payment forms.

## Overview

The Square Web Payments SDK provides a secure, PCI-compliant way to accept card payments directly in your application without redirecting users to an external checkout page. The SDK tokenizes card details on the client side and sends a secure token to your backend for processing.

## Backend Setup

### 1. Environment Variables

Add these to your `repro-backend/.env` file:

```env
SQUARE_ACCESS_TOKEN=EAAAlwwtMDNzksTtV1dpOEQNqECFUwv_7mAGTsK9VpCgqO5WfAgEN0s9zsyFiLfv
SQUARE_APPLICATION_ID=sandbox-sq0idb-KBncaaZuhXcaX42j5O7zdg
SQUARE_LOCATION_ID=your_location_id_here
SQUARE_ENVIRONMENT=sandbox
SQUARE_CURRENCY=USD
```

**For Production:**
```env
SQUARE_ACCESS_TOKEN=EAAAly-d0wuus8_9xEHnKok37ibsM8W_mE2YpQO63d_-SUZa7T3vjS7DdTGxXHGe
SQUARE_APPLICATION_ID=sq0idp-VwrHAzcPpOOEPyCQSgn1Dg
SQUARE_LOCATION_ID=your_production_location_id
SQUARE_ENVIRONMENT=production
SQUARE_CURRENCY=USD
```

### 2. API Endpoint

The backend now includes a new endpoint:

**POST `/api/payments/create`**

This endpoint accepts:
- `sourceId` (required): The token from Square Web Payments SDK
- `amount` (required): Payment amount in dollars
- `currency` (optional): Currency code (defaults to USD)
- `shoot_id` (optional): Link payment to a shoot
- `buyer` (optional): Buyer information from tokenization

## Frontend Setup

### 1. Environment Variables

Add these to your `repro-frontend/.env` file:

```env
VITE_SQUARE_APPLICATION_ID=sandbox-sq0idb-KBncaaZuhXcaX42j5O7zdg
VITE_SQUARE_LOCATION_ID=your_location_id_here
```

**For Production:**
```env
VITE_SQUARE_APPLICATION_ID=sq0idp-VwrHAzcPpOOEPyCQSgn1Dg
VITE_SQUARE_LOCATION_ID=your_production_location_id
```

### 2. Using the SquarePaymentForm Component

The `SquarePaymentForm` component is located at:
`repro-frontend/src/components/payments/SquarePaymentForm.tsx`

**Basic Usage:**

```tsx
import { SquarePaymentForm } from '@/components/payments/SquarePaymentForm';

function CheckoutPage() {
  const amount = 100.00; // Payment amount
  const shootId = '123'; // Optional: link to a shoot

  return (
    <div>
      <h2>Complete Payment</h2>
      <SquarePaymentForm
        amount={amount}
        currency="USD"
        shootId={shootId}
        onPaymentSuccess={(payment) => {
          console.log('Payment successful:', payment);
          // Redirect or show success message
        }}
        onPaymentError={(error) => {
          console.error('Payment failed:', error);
        }}
      />
    </div>
  );
}
```

**Props:**
- `amount` (required): Payment amount in dollars
- `currency` (optional): Currency code (default: 'USD')
- `shootId` (optional): Shoot ID to link the payment
- `onPaymentSuccess` (optional): Callback when payment succeeds
- `onPaymentError` (optional): Callback when payment fails
- `disabled` (optional): Disable the form

## How It Works

1. **SDK Loading**: The component automatically loads the Square Web Payments SDK script
2. **Card Input**: Square renders a secure iframe for card input (PCI compliant)
3. **Tokenization**: When the user submits, the SDK tokenizes the card details
4. **Backend Processing**: The token is sent to `/api/payments/create`
5. **Payment Completion**: The backend processes the payment via Square Payments API
6. **Response**: Success or error is returned to the frontend

## Security Considerations

1. **HTTPS Required**: Square Web Payments SDK requires HTTPS in production
2. **No Card Data**: Card details never touch your server - only tokens
3. **PCI Compliance**: Square handles PCI compliance through their iframe
4. **Token Security**: Tokens are single-use and expire quickly
5. **Environment Variables**: Never commit credentials to version control

## Testing

### Sandbox Testing

1. Use sandbox credentials in `.env` files
2. Use Square's test card numbers:
   - **Success**: `4111 1111 1111 1111`
   - **Decline**: `4000 0000 0000 0002`
   - **3D Secure**: `4000 0027 6000 3184`
3. Use any future expiry date and any 3-digit CVV

### Production

1. Switch to production credentials
2. Ensure HTTPS is enabled
3. Test with real cards in small amounts first

## Differences from Checkout Links

**Old Approach (Checkout Links):**
- Redirects user to Square's hosted checkout page
- Less control over UI/UX
- Requires redirect handling

**New Approach (Web Payments SDK):**
- Embedded payment form in your application
- Full control over UI/UX
- No redirects needed
- Better user experience
- Supports 3D Secure / SCA

## Troubleshooting

### SDK Not Loading
- Check browser console for script loading errors
- Ensure HTTPS is enabled (required in production)
- Verify application ID is correct

### Tokenization Fails
- Check that location ID matches between frontend and backend
- Verify application ID is correct
- Check browser console for SDK errors

### Payment Processing Fails
- Verify access token is correct
- Check that location ID is set correctly
- Review Laravel logs for API errors
- Ensure amount is in the correct format (dollars, not cents)

## Additional Resources

- [Square Web Payments SDK Documentation](https://developer.squareup.com/docs/web-payments/overview)
- [Square PHP SDK Documentation](https://github.com/square/square-php-sdk)
- [Square API Reference](https://developer.squareup.com/reference/square)


