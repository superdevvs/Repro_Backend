<?php

namespace App\Support;

/** Presentation schema for current results and historical stored provider diagnostics. */
class BrightMlsPublicResult
{
    public static function from(mixed $value): array
    {
        $result = is_array($value) ? $value : [];
        $success = ($result['success'] ?? false) === true;
        $status = in_array($result['status'] ?? null, ['published', 'error', 'validation_error', 'simulated'], true)
            ? $result['status'] : ($success ? 'published' : 'error');
        $validation = [];
        if (!$success && $status === 'validation_error' && is_array($result['validation_errors'] ?? null)) {
            foreach (array_slice($result['validation_errors'], 0, 50) as $message) {
                // Reviewed local strategy validation templates, never upstream details.
                if (is_string($message) && preg_match('/\AItem [0-9]{1,6}: (?:fileName must be 50 characters or fewer\.|description must be 50 characters or fewer\.|photo fileName must end with \.jpg or \.jpeg\.|photo imageUrls\.fullSize must be a valid URL\.|(?:floor_plan|document) (?:fileName must end with \.pdf\.|docUrl must be a valid URL\.)|tour_url (?:fileName must be 25 characters or fewer\.|tourUrl must be a valid URL\.|must be an unbranded URL\.))\z/', $message)) {
                    $validation[] = $message;
                }
            }
        }
        if (!$success && $status === 'validation_error' && is_string($result['error'] ?? null)
            && ($result['error'] === 'At least one media item is required to publish a manifest'
                || preg_match('/\AMaximum [0-9]{1,6} (?:photos|tour URLs) allowed per listing \(received [0-9]{1,6}\)\.\z/', $result['error']))) {
            $validation[] = $result['error'];
        }
        $public = [
            'success' => $success, 'status' => $status,
            'message' => $success ? 'Published to Bright MLS' : ($validation ? 'Review the listing media before publishing.' : 'Bright MLS could not publish this listing. Check the integration settings and try again.'),
            'validation_errors' => $validation,
        ];
        foreach (['manifest_id', 'manifest_uuid', 'mls_id', 'published_at'] as $key) {
            $item = $result[$key] ?? null;
            if ((is_string($item) || is_int($item)) && preg_match('/\A[A-Za-z0-9_.:+-]{1,100}\z/', (string) $item)) $public[$key] = $item;
        }
        foreach (['mode' => ['new', 'legacy'], 'environment' => ['production', 'staging', 'test', 'sandbox']] as $key => $allowed) {
            if (in_array($result[$key] ?? null, $allowed, true)) $public[$key] = $result[$key];
        }
        return $public;
    }
}
