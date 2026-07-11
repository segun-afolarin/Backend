<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Used by ReportController for AI photo-verification on submitted reports.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
    ],

    // Used by HelpCenterChatController for the public help-desk chat — kept
    // as its own key/quota so a busy help center can't starve report photo
    // verification (and vice versa).
    'gemini_help_center' => [
        'key' => env('GEMINI_HELP_CENTER_API_KEY'),
    ],

];