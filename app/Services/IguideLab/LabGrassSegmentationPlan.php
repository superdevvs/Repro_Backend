<?php
namespace App\Services\IguideLab;

use Illuminate\Support\Str;

/** Explicit 2:1 source role capability; no changes to the square staging validator. */
class LabGrassSegmentationPlan
{
    public function validate(array $plan, string $requestId): void
    {
        $this->keys($plan, ['stepKey','maskRole','requestId','reservationState','model','inputSha256','width','height','payload',
            'expectedIdentities','selectionOrigin','assessmentProposalSha256','contextSha256','maximumPostAttempts','automaticRetry','semanticClassVerified','inputFingerprint']);
        $role = $plan['maskRole'];
        abort_unless(is_string($role) && in_array($role, LabGrassAssessmentPolicy::ROLES, true)
            && $plan['stepKey'] === 'source-grass-'.$role && Str::isUuid($requestId) && $plan['requestId'] === $requestId
            && $plan['reservationState'] === 'server_reserved' && $plan['model'] === LabMachineSegmentationTransport::MODEL
            && [$plan['width'], $plan['height']] === [2048, 1024] && $plan['selectionOrigin'] === 'machine'
            && $plan['maximumPostAttempts'] === 1 && $plan['automaticRetry'] === false && $plan['semanticClassVerified'] === false, 422, 'Unsupported source-sphere grass reservation.');
        foreach (['inputSha256','assessmentProposalSha256','contextSha256','inputFingerprint'] as $key) {
            abort_unless(is_string($plan[$key]) && preg_match('/\A[a-f0-9]{64}\z/D', $plan[$key]), 422);
        }
        $unhashed = $plan; unset($unhashed['inputFingerprint']);
        abort_unless(hash_equals($plan['inputFingerprint'], LabMachineSegmentationPlan::canonicalHash($unhashed)), 422, 'Grass plan changed.');
        $identities = $plan['expectedIdentities']; $payload = $plan['payload'];
        abort_unless(is_array($identities) && array_is_list($identities) && count($identities) >= 1 && count($identities) <= 8
            && $identities === array_map(fn ($i) => $role.'_'.$i, range(1, count($identities))) && is_array($payload), 422);
        $this->keys($payload, ['prompt','box_prompts','apply_mask','output_format','return_multiple_masks','max_masks','include_scores','include_boxes']);
        abort_unless($payload['prompt'] === LabGrassAssessmentPolicy::PROMPTS[$role] && $payload['apply_mask'] === false
            && $payload['output_format'] === 'png' && $payload['return_multiple_masks'] === true && $payload['max_masks'] === count($identities)
            && $payload['include_scores'] === true && $payload['include_boxes'] === true && is_array($payload['box_prompts'])
            && array_is_list($payload['box_prompts']) && count($payload['box_prompts']) === count($identities), 422, 'Grass role settings changed.');
        foreach ($payload['box_prompts'] as $i => $box) {
            abort_unless(is_array($box), 422); $this->keys($box, ['x_min','y_min','x_max','y_max','object_id']);
            foreach ($box as $v) abort_unless(is_int($v), 422);
            abort_unless($box['object_id'] === $i + 1 && 0 <= $box['x_min'] && $box['x_min'] < $box['x_max'] && $box['x_max'] <= 2047
                && 0 <= $box['y_min'] && $box['y_min'] < $box['y_max'] && $box['y_max'] <= 1023, 422, 'Grass selection is out of bounds.');
        }
    }
    public function payload(array $plan, string $bytes): array
    {
        $this->validate($plan, $plan['requestId'] ?? '');
        abort_unless(strlen($bytes) <= 30 * 1024 * 1024 && hash_equals($plan['inputSha256'], hash('sha256', $bytes)), 422);
        $size = @getimagesizefromstring($bytes);
        abort_unless($size && [$size[0], $size[1], $size['mime']] === [2048, 1024, 'image/png'], 422, 'Grass segmentation requires the exact original 2048x1024 PNG.');
        $decoded = @imagecreatefromstring($bytes); abort_unless($decoded !== false, 422); imagedestroy($decoded);
        return ['image_url' => 'data:image/png;base64,'.base64_encode($bytes)] + $plan['payload'];
    }
    private function keys(array $input, array $keys): void
    {
        $actual = array_keys($input); sort($actual); sort($keys); abort_unless($actual === $keys, 422, 'Unexpected grass plan fields.');
    }
}
