<?php

return [
    // Read only by the Studio worker. Never place the credential itself in config/cache.
    'openai_primary_key_path' => storage_path('app/private/iguide-lab-credentials/openai-primary.key'),
    'pipeline_python_path' => '/home/maverick/iguide-ai-runtime-v3/venv-py312-np254-pil1300/bin/python',
];
