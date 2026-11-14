<?php

namespace App\Services\IguideLab;

use Illuminate\Support\Str;

/** Strict subset of the verified SAM schema, derived by trusted placement assessment. */
class LabLifestyleSegmentationPlan
{
    public function validate(array $plan, string $requestId): void
    {
        $fields = ['stepKey','requestId','reservationState','model','inputSha256','width','height','payload','expectedIdentities','selectionOrigin',
            'assessmentProposalSha256','contextSha256','maximumPostAttempts','automaticRetry','identityCaveat','inputFingerprint'];
        $this->keys($plan, $fields);
        abort_unless(Str::isUuid($requestId) && $plan['requestId'] === $requestId && $plan['reservationState'] === 'server_reserved'
            && $plan['model'] === LabMachineSegmentationTransport::MODEL && $plan['width'] === 2048 && $plan['height'] === 2048
            && $plan['selectionOrigin'] === 'machine' && $plan['maximumPostAttempts'] === 1 && $plan['automaticRetry'] === false,
            422, 'Segmentation plan is not a supported machine reservation.');
        foreach (['inputSha256','assessmentProposalSha256','contextSha256','inputFingerprint'] as $key) abort_unless(is_string($plan[$key]) && preg_match('/\A[a-f0-9]{64}\z/D', $plan[$key]), 422, 'Invalid segmentation fingerprint.');
        $unhashed = $plan; unset($unhashed['inputFingerprint']);
        abort_unless(hash_equals($plan['inputFingerprint'], self::canonicalHash($unhashed)), 422, 'Segmentation plan changed.');
        $identities = match ($plan['stepKey']) { 'source-floor' => ['floor'], 'candidate-people' => ['adult_1','adult_2'], default => null };
        abort_unless($identities !== null && $plan['expectedIdentities'] === $identities && is_array($plan['payload']), 422, 'Unsupported segmentation role.');
        $payload = $plan['payload'];
        $this->keys($payload, ['prompt','box_prompts','apply_mask','output_format','return_multiple_masks','max_masks','include_scores','include_boxes']);
        abort_unless($payload['prompt'] === ($plan['stepKey'] === 'source-floor' ? 'floor' : 'person')
            && $payload['apply_mask'] === false && $payload['output_format'] === 'png' && $payload['return_multiple_masks'] === true
            && $payload['max_masks'] === count($identities) && $payload['include_scores'] === true && $payload['include_boxes'] === true
            && is_array($payload['box_prompts']) && array_is_list($payload['box_prompts']) && count($payload['box_prompts']) === count($identities), 422, 'Unsupported segmentation settings.');
        foreach ($payload['box_prompts'] as $i => $box) {
            abort_unless(is_array($box), 422); $this->keys($box, ['x_min','y_min','x_max','y_max','object_id']);
            foreach ($box as $number) abort_unless(is_int($number), 422, 'Segmentation boxes must use integer pixels.');
            abort_unless($box['object_id'] === $i + 1 && $box['x_min'] >= 0 && $box['y_min'] >= 0 && $box['x_max'] < 2048 && $box['y_max'] < 2048
                && $box['x_min'] < $box['x_max'] && $box['y_min'] < $box['y_max'], 422, 'Segmentation selection is out of bounds.');
        }
    }

    public function payload(array $plan, string $bytes): array
    {
        $this->validate($plan, $plan['requestId'] ?? '');
        abort_unless(strlen($bytes) <= 30 * 1024 * 1024 && hash_equals($plan['inputSha256'], hash('sha256', $bytes)), 422, 'Segmentation source changed.');
        $size = @getimagesizefromstring($bytes);
        abort_unless($size && $size['mime'] === 'image/png' && [$size[0],$size[1]] === [2048,2048], 422, 'Segmentation input must be the exact2048square PNG.');
        $decoded = @imagecreatefromstring($bytes); abort_unless($decoded !== false, 422, 'Segmentation source cannot be decoded.'); imagedestroy($decoded);
        return ['image_url' => 'data:image/png;base64,'.base64_encode($bytes)] + $plan['payload'];
    }
    /** Python protocol uses sorted, compact ASCII JSON; distinct from workflow's own input digest. */
    public static function canonicalHash(array $input): string
    {
        $sort = function ($value) use (&$sort) {
            if (! is_array($value)) return $value;
            if (! array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as $key => $entry) $value[$key] = $sort($entry);
            return $value;
        };
        return hash('sha256', json_encode($sort($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
    private function keys(array $input, array $keys): void { $actual = array_keys($input); sort($actual); sort($keys); abort_unless($actual === $keys, 422, 'Unexpected segmentation fields.'); }
}
