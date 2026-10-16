<?php

declare(strict_types=1);

// Public browser endpoint can differ from the internal publisher address.
// Never expose the app secret to a browser.
return [
    'attachments' => [
        'root' => env('TEAMCHAT_PRIVATE_ROOT', storage_path('app/private/teamchat')),
        'max_bytes' => (int) env('TEAMCHAT_MAX_UPLOAD_BYTES', 20 * 1024 * 1024),
        'max_count' => (int) env('TEAMCHAT_MAX_ATTACHMENTS', 4),
        'max_pixels' => 8000000, 'image_edge' => 2048, 'voice_seconds' => 300, 'staging_hours' => 24,
        'require_scan' => (bool) env('TEAMCHAT_REQUIRE_SCAN', false),
        'ffmpeg' => env('TEAMCHAT_FFMPEG', 'ffmpeg'), 'ffprobe' => env('TEAMCHAT_FFPROBE', 'ffprobe'),
        'types' => [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
            'webm' => ['audio/webm', 'video/webm'], 'ogg' => ['audio/ogg', 'application/ogg'], 'mp4' => ['audio/mp4', 'video/mp4'],
            'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'], 'wav' => ['audio/wav', 'audio/x-wav'], 'mp3' => ['audio/mpeg'],
            'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        ],
    ],
    'reverb_host' => env('TEAMCHAT_REVERB_HOST', env('REVERB_HOST', '127.0.0.1')),
    'reverb_port' => (int) env('TEAMCHAT_REVERB_PORT', env('REVERB_PORT', 443)),
    'reverb_scheme' => env('TEAMCHAT_REVERB_SCHEME', env('REVERB_SCHEME', 'https')),
];
