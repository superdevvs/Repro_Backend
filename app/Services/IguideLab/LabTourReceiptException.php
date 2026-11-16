<?php
namespace App\Services\IguideLab;

/**
 * A provider response arrived but its body or receipt could not be committed (spec 5.6 attempt steps 6-8). The answer
 * may be paid and stored, so the survey goes to needs_attention (receipt_unconfirmed) and is never re-sent.
 */
final class LabTourReceiptException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode = 'receipt_unconfirmed', ?\Throwable $previous = null)
    {
        parent::__construct($reasonCode, 0, $previous);
    }
}
