<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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

    'tavily' => [
        'api_key' => env('TAVILY_API_KEY', ''),
        'base_url' => env('TAVILY_BASE_URL', 'https://api.tavily.com'),
    ],

    'browser' => [
        'headed' => env('BROWSER_HEADED', 'true'),
        'cdp_port' => env('CHROME_CDP_PORT', ''),
    ],

    'google' => [
        'sheets_service_account' => env(
            'GOOGLE_SHEETS_SERVICE_ACCOUNT',
            storage_path('rdl-478707-2c066429878f.json'),
        ),
        'youtube_client_id' => env('YOUTUBE_CLIENT_ID', ''),
        'youtube_client_secret' => env('YOUTUBE_CLIENT_SECRET', ''),
        'youtube_refresh_token' => env('YOUTUBE_REFRESH_TOKEN', ''),
        'youtube_redirect_uri' => env('YOUTUBE_REDIRECT_URI', ''),
        'youtube_upload_timeout' => (int) env('YOUTUBE_UPLOAD_TIMEOUT', 1800),
    ],

    'facebook' => [
        'graph_url' => env('FACEBOOK_GRAPH_URL', 'https://graph.facebook.com/v21.0'),
    ],

    'instagram' => [
        'graph_url' => env('INSTAGRAM_GRAPH_URL', 'https://graph.facebook.com/v21.0'),
    ],

    'openwa' => [
        'base_url' => env('OPENWA_BASE_URL', 'http://127.0.0.1:2785/api'),
        'api_key' => env('OPENWA_API_KEY', ''),
        'session_id' => env('OPENWA_SESSION_ID', ''),
    ],

    'docker' => [
        'binary' => env('DOCKER_BINARY', 'docker'),
        'host' => env('DOCKER_HOST', ''),
        'timeout' => (int) env('DOCKER_TIMEOUT', 60),
    ],

    'cloudflare' => [
        'api_url' => env('CLOUDFLARE_API_URL', 'https://api.cloudflare.com/client/v4'),
    ],

];
