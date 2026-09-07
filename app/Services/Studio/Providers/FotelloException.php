<?php

namespace App\Services\Studio\Providers;

use RuntimeException;

/** Contains only neutral, locally authored diagnostics; never attach the provider response. */
class FotelloException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = false,
        public readonly bool $ambiguousOutcome = false,
    ) {
        parent::__construct(match ($category) {
            'configuration' => 'Photo editing is not fully configured. Contact your administrator.',
            'request_contract' => 'These photo editing settings are not supported by the configured integration.',
            'response_contract' => 'The photo editing service returned an incomplete response. Saved request details are retained.',
            'unsafe_url' => 'The photo editing service returned an unapproved file location.',
            'transfer_limit' => 'This photo editing file exceeds the supported transfer size.',
            'account' => 'The photo editing account needs attention. Contact your administrator.',
            'rejected' => 'The photo editing service could not accept this request. Review the selected images and settings.',
            'revision_rejected' => 'The photo editing service could not accept this revision.',
            default => $ambiguousOutcome
                ? 'The photo editing submission could not be confirmed. Check its saved status before submitting again.'
                : 'The photo editing service is temporarily unavailable. Retry using the saved request.',
        });
    }
}
