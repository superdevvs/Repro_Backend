<?php
// The one text/vision reasoning model every iGUIDE lab analysis, assessment, framing, evaluation and survey request
// uses (image generation models are configured elsewhere). Not hash-pinned: never move these keys into
// config/iguide-lab.php, which LabStagingLayouts hashes. Read through LabReasoningModel, never env() directly.
//
// 'allowed' is also the validation allow-list for stored records: a historical record stays valid while its model is
// listed here, whichever model is current. Keep it equal to REASONING_MODELS in
// resources/iguide-lab/staging_assessment_protocol.py (IguideLabReasoningModelTest checks this).
// Changing 'reasoning' needs `php artisan config:cache` and `php artisan queue:restart`.
return [
    'reasoning' => env('IGUIDE_LAB_REASONING_MODEL', 'gpt-6-astra'),
    'allowed' => ['gpt-6-astra', 'gpt-6.1-terra', 'gpt-6-sol'],
    // Allowed models confirmed to accept input_image detail "original" (lifestyle native details, interior material
    // source details; docs/iguide-lifestyle-original-detail-transport.md). A request that would send "original" to any
    // other model is refused before the paid call. Add a model only after confirming its original-detail sizing.
    'original_detail' => ['gpt-6-astra'],
];
