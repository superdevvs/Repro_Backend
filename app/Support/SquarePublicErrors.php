<?php

namespace App\Support;

class SquarePublicErrors
{
    private const MESSAGES = [
        'CARD_DECLINED' => 'Your card was declined. Try another card or contact your bank.',
        'GENERIC_DECLINE' => 'Your card was declined. Try another card or contact your bank.',
        'INSUFFICIENT_FUNDS' => 'The card has insufficient funds. Try another payment method.',
        'CARD_EXPIRED' => 'This card has expired. Use a current card.',
        'EXPIRATION_FAILURE' => 'Check the card expiration date and try again.',
        'INVALID_EXPIRATION' => 'Check the card expiration date and try again.',
        'VERIFY_CVV_FAILURE' => 'Check the card security code and try again.',
        'VERIFY_AVS_FAILURE' => 'Check the billing address and postal code and try again.',
        'INVALID_CARD' => 'Check the card details or use another card.',
        'CVV_FAILURE' => 'Check the card security code and try again.',
        'PAYMENT_LIMIT_EXCEEDED' => 'This payment exceeds the card limit. Try another payment method.',
    ];

    public static function from(mixed $errors): array
    {
        $safe = [];
        foreach (is_array($errors) ? array_slice($errors, 0, 10) : [] as $error) {
            $code = is_array($error) ? ($error['code'] ?? null)
                : (is_object($error) && method_exists($error, 'getCode') ? $error->getCode() : null);
            if (!is_string($code) || !isset(self::MESSAGES[$code])) $code = 'PAYMENT_FAILED';
            $safe[] = ['code' => $code, 'detail' => self::MESSAGES[$code] ?? 'Payment could not be completed. Please try again or use another payment method.'];
        }
        return $safe ?: [['code' => 'PAYMENT_FAILED', 'detail' => 'Payment could not be completed. Please try again or use another payment method.']];
    }
}
