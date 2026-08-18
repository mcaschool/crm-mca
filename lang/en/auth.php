<?php

declare(strict_types=1);

// Auth messages. Intentionally GENERIC: they never reveal whether the email exists.
return [
    'failed' => 'These credentials are not correct.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many failed attempts. Please try again in :seconds seconds.',
    'two_factor_failed' => 'The verification code is invalid.',
];
