<?php

declare(strict_types=1);

// Public browser endpoint can differ from the internal publisher address.
// Never expose the app secret to a browser.
return [
    'reverb_host' => env('TEAMCHAT_REVERB_HOST', env('REVERB_HOST', '127.0.0.1')),
    'reverb_port' => (int) env('TEAMCHAT_REVERB_PORT', env('REVERB_PORT', 443)),
    'reverb_scheme' => env('TEAMCHAT_REVERB_SCHEME', env('REVERB_SCHEME', 'https')),
];
