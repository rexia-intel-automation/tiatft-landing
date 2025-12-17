<?php
return [
    'webhook_url' => 'https://rexia-main.up.railway.app/webhook/reg-tiatft',

    'rate_limit' => [
        'max_attempts' => 3,
        'time_window' => 60,
    ],

    'validation' => [
        'min_name_length' => 2,
        'min_platform_length' => 2,
        'temp_email_domains' => [
            'tempmail.com',
            'guerrillamail.com',
            'throwaway.email',
            '10minutemail.com',
            'mailinator.com',
            'maildrop.cc',
        ]
    ],

    'debug_mode' => false,
];
