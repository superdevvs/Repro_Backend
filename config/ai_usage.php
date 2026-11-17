<?php

// USD per million tokens. Estimates use standard API rates, not invoice amounts.
// Verified 2026-11-17: https://developers.openai.com/api/docs/pricing
// Keys are model ids without a dated snapshot suffix (AiUsageRecorder::estimate strips -YYYY-MM-DD). They may contain
// dots, so read them from the prices array, never through config('ai_usage.prices.<model>').
return [
    'pricing_version' => '2026-11-17',
    'prices' => [
        'gpt-4o' => ['input' => 2.50, 'cached' => 1.25, 'output' => 10.00],
        'gpt-6-astra' => ['input' => 10.00, 'cached' => 1.00, 'output' => 50.00],
        'gpt-6-sol' => ['input' => 2.00, 'cached' => 0.20, 'output' => 10.00],
        'gpt-6.1-sol' => ['input' => 2.00, 'cached' => 0.10, 'output' => 10.00],
        'gpt-6.1-terra' => ['input' => 1.00, 'cached' => 0.05, 'output' => 5.00],
        'gpt-6-luna' => ['input' => 0.10, 'cached' => 0.01, 'output' => 0.50],
        'gpt-image-2' => ['text_input' => 2.50, 'image_input' => 4.00, 'output' => 15.00],
        'gpt-image-2.6-sunburst' => ['text_input' => 5.00, 'image_input' => 8.00, 'output' => 30.00],
    ],
];
