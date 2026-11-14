<?php
namespace App\Services\IguideLab;

use InvalidArgumentException;

/** New wire representation: seven failed-native pairs plus complete bounded check feedback. */
final class LabRepairAnalysisProtocol
{
    public const VERSION = 'responses-lighting-repair-context-v1';
    public static function compile(array $recipe, array $context, array $images): array
    {
        self::validate($context);
        $base = $context; unset($base['repairContext']);
        $payload = LabAnalysisRequestV1::compile($recipe, $base, $images);
        foreach ($images as $i => $image) {
            $bytes = base64_decode(substr($image, strpos($image, ',') + 1), true); $view = $context['repairContext']['views'][$i];
            $size = getimagesizefromstring($bytes);
            if (hash('sha256', $bytes) !== $view['sha256'] || [$size[0], $size[1]] !== [2048, 1024]) self::invalid();
            $payload['input'][0]['content'][1 + 2 * $i]['text'] = 'View ID: '.$view['id'].'; evaluation evidence: '.$view['evidenceViewId'].'. SOURCE LEFT is geometry authority. FAILED CANDIDATE RIGHT is failure context only; do not copy its errors. Same native capture, not an independent camera.';
        }
        $payload['input'][0]['content'][0]['text'] = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return $payload;
    }
    public static function parse(array $response, array $context): array
    {
        self::validate($context); unset($context['repairContext']);
        return LabScenePlanProtocol::parse($response, $context);
    }
    private static function validate(array $context): void
    {
        $r = $context['repairContext'] ?? [];
        if (array_diff(array_keys($r), ['version','sourceSha256','candidateSha256','intentSha256','evidenceManifestSha256','authority','originalIntent','feedback','sameCaptureOnly','candidateIsGeometryAuthority','views'])
            || count($r) !== 11 || ($r['version'] ?? null) !== LabWorkflowRepairContext::VERSION
            || ($context['projection'] ?? null) !== 'equirectangular' || ($context['viewIds'] ?? null) !== LabWorkflowRepairContext::IDS
            || ($r['sourceSha256'] ?? null) !== ($context['sourceSha256'] ?? null) || ($r['sameCaptureOnly'] ?? null) !== true
            || ($r['candidateIsGeometryAuthority'] ?? null) !== false || ($r['feedback']['qualityStatus'] ?? null) !== 'needs_review'
            || ($r['originalIntent']['sourceSha256'] ?? null) !== $r['sourceSha256']
            || ($r['originalIntent']['preset'] ?? null) !== ($context['preset'] ?? null)
            || ($r['originalIntent']['style'] ?? null) !== ($context['style'] ?? null)
            || LabAnalysisRecipes::hash($r['originalIntent']) !== ($r['intentSha256'] ?? null)
            || LabAnalysisRecipes::hash($r['feedback']) !== ($r['authority']['resultSha256'] ?? null)
            || ($r['authority']['evidenceSha256'] ?? null) !== $r['evidenceManifestSha256']
            || array_column($r['views'] ?? [], 'id') !== LabWorkflowRepairContext::IDS
            || array_column($r['views'] ?? [], 'evidenceViewId') !== LabVisualQualityProtocol::VIEW_IDS
            || strlen(json_encode($r, JSON_THROW_ON_ERROR)) > 64 * 1024) self::invalid();
        foreach (['sourceSha256','candidateSha256','intentSha256','evidenceManifestSha256'] as $key)
            if (! is_string($r[$key] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $r[$key])) self::invalid();
        $a = $r['authority'];
        if (array_diff(array_keys($a), ['version','evaluationId','resultSha256','evidenceSha256','responseSha256']) || count($a) !== 5
            || ($a['version'] ?? null) !== LabWorkflowRepairContext::VERSION
            || ! \Illuminate\Support\Str::isUuid($a['evaluationId'] ?? '')) self::invalid();
        foreach (['resultSha256','evidenceSha256','responseSha256'] as $key)
            if (! is_string($a[$key] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $a[$key])) self::invalid();
        $f = $r['feedback'];
        if (array_diff(array_keys($f), ['protocolVersion','qualityStatus','checks','unresolvedDependencies','recommendedAction','summary','manualAcceptance','crossViewVerified']) || count($f) !== 8
            || ($f['protocolVersion'] ?? null) !== LabVisualQualityProtocol::VERSION || ($f['manualAcceptance'] ?? null) !== false
            || ($f['crossViewVerified'] ?? null) !== false || ! in_array($f['recommendedAction'] ?? null, LabVisualQualityProtocol::ACTIONS, true)
            || ! is_string($f['summary'] ?? null) || mb_strlen($f['summary']) < 1 || mb_strlen($f['summary']) > 1200
            || ! is_array($f['unresolvedDependencies'] ?? null) || ! array_is_list($f['unresolvedDependencies']) || count($f['unresolvedDependencies']) > 12
            || array_diff(array_keys($f['checks'] ?? []), LabVisualQualityProtocol::CHECKS) || count($f['checks'] ?? []) !== 7) self::invalid();
        foreach ($f['unresolvedDependencies'] as $text) if (! is_string($text) || mb_strlen($text) < 1 || mb_strlen($text) > 600) self::invalid();
        foreach ($f['checks'] as $check) {
            if (array_diff(array_keys($check), ['status','evidenceViewIds','reason']) || count($check) !== 3
                || ! in_array($check['status'] ?? null, ['pass','fail','uncertain'], true)
                || ! is_string($check['reason'] ?? null) || mb_strlen($check['reason']) < 1 || mb_strlen($check['reason']) > 900
                || ! is_array($check['evidenceViewIds'] ?? null) || ! array_is_list($check['evidenceViewIds'])
                || count($check['evidenceViewIds']) < 1 || count($check['evidenceViewIds']) > 7
                || array_diff($check['evidenceViewIds'], LabVisualQualityProtocol::VIEW_IDS)
                || count(array_unique($check['evidenceViewIds'])) !== count($check['evidenceViewIds'])) self::invalid();
        }
        foreach ($r['views'] as $view)
            if (array_diff(array_keys($view), ['id','evidenceViewId','sha256','width','height','role']) || count($view) !== 6
                || [$view['width'] ?? null,$view['height'] ?? null] !== [2048,1024]
                || ($view['role'] ?? null) !== 'source_left_failed_candidate_right' || ! preg_match('/\A[a-f0-9]{64}\z/D', $view['sha256'] ?? '')) self::invalid();
    }
    private static function invalid(): never { throw new InvalidArgumentException('Repair context/evidence binding is invalid.'); }
}
