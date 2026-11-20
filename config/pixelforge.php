<?php

return [
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'markup_percent' => (float) env('PIXELFORGE_MARKUP_PERCENT', 25),
];
