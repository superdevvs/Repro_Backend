<?php
namespace App\Services\IguideLab;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A capacity hold is not a provider failure and never implies a new paid attempt. */
final class LabWorkflowCapacity extends HttpException
{
    public function __construct(public readonly string $reason, public readonly ?int $nextEligibleAt)
    {
        parent::__construct(429, $nextEligibleAt!==null
            ? 'Queued until the rolling processing limit has capacity.'
            : ($reason==='project_image_capacity'?'This lab has reached its configured image allowance. Existing results were retained.'
                :'This workflow reached its reserved processing allowance. Existing results were retained.'));
    }
}
