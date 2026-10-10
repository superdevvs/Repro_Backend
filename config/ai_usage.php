<?php

// USD per million tokens. Estimates use standard API rates, not invoice amounts.
// Verified 2026-10-10: https://developers.openai.com/api/docs/pricing
return [
    'pricing_version' => '2026-10-10',
    'prices' => [
        'gpt-4o' => ['input' => 2.50, 'cached' => 1.25, 'output' => 10.00],
        'gpt-image-2' => ['text_input' => 2.50, 'image_input' => 4.00, 'output' => 15.00],
    ],
];
