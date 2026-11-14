<?php

return [
    // Separate internal placement protocol; no default manifest means unavailable.
    'staging_assessment_runtime_manifest' => '/home/maverick/iguide-ai-runtime-staging-assessment-v1/runtime-manifest.json',
    // Read only by the Studio worker. Never place the credential itself in config/cache.
    'openai_primary_key_path' => storage_path('app/private/iguide-lab-credentials/openai-primary.key'),
    'pipeline_python_path' => '/home/maverick/iguide-ai-runtime-v3/venv-py312-np254-pil1300/bin/python',
    // A separate validation manifest gates unattended processing; existing runtimes are never modified.
    'workflow_runtime_manifest' => '/home/maverick/iguide-ai-runtime-workflow-v1/runtime-manifest.json',
    // Separate validated runtime; ordinary section/grass work never imports SciPy.
    'harmonization_python_path' => '/home/maverick/iguide-ai-runtime-harmonic-v1/venv-py312-np254-pil1300-scipy1181/bin/python',
    'harmonization_runtime_manifest' => '/home/maverick/iguide-ai-runtime-harmonic-v1/runtime-manifest.json',
];
