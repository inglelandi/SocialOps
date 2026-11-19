<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        // USD per million tokens, for the cost column on the Jobs page; leave empty to show tokens only
        'price_per_mtok' => ['input' => env('ANTHROPIC_PRICE_INPUT'), 'output' => env('ANTHROPIC_PRICE_OUTPUT')],
    ],

    // our own platform apps (Phase 0): create them in each developer portal and paste the ids here
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'graph_version' => env('META_GRAPH_VERSION', 'v26.0'),
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'audited' => (bool) env('TIKTOK_AUDITED', false), // public posts go to Manual-Needed until TikTok's audit passes
    ],

    'google' => [
        'service_account' => env('GOOGLE_SERVICE_ACCOUNT_JSON'), // path to the key file of the service account that reads shared Drive folders
    ],

    // Canva Connect integration (optional): imports designs into the asset inbox
    'canva' => [
        'client_id' => env('CANVA_CLIENT_ID'),
        'client_secret' => env('CANVA_CLIENT_SECRET'),
    ],

    // Dropbox app (optional): pulls a shared folder into the asset inbox, OAuth with PKCE so only the app key is needed
    // the Inglelandi NAS connector (D29, D30): its address, and the download key it shares with this server so Claude can
    // put photos and videos from a client's NAS folder on posts (secrets/download_key on the NAS, 32 characters or more)
    'nas' => [
        'url' => env('NAS_CONNECTOR_URL'),
        'download_key' => env('NAS_DOWNLOAD_KEY'),
    ],

    'dropbox' => [
        'app_key' => env('DROPBOX_APP_KEY'),
    ],

    // ffmpeg assembles Reels from clips; the full path when the queue runs with a bare cron PATH
    'ffmpeg' => [
        'path' => env('FFMPEG_PATH', 'ffmpeg'),
    ],

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
        'webhook_url' => env('SLACK_WEBHOOK_URL'), // incoming webhook for staff alerts; empty = no Slack
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
