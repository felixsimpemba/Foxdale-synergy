<?php

$uploadExtensions = ['png', 'jpg', 'jpeg', 'pdf', 'txt', 'log', 'csv', 'json', 'docx', 'xlsx', 'zip'];

return [

    /*
    |--------------------------------------------------------------------------
    | Display timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC and returned as ISO 8601. This timezone is
    | the default used for display, reports and business-day calculations.
    |
    */

    'display_timezone' => env('INCIDENTS_DISPLAY_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    'auth' => [
        // Lifetime of tokens issued by /auth/login and /auth/refresh.
        'session_token_minutes' => (int) env('SANCTUM_EXPIRATION', 480),

        // Personal tokens (scripts, MCP clients) may live longer, up to this limit.
        'personal_token_default_days' => 90,
        'personal_token_max_days' => 365,

        // Failed logins per email before the account is locked, and for how long.
        'lockout_attempts' => 5,
        'lockout_minutes' => 15,

        'two_factor_required_roles' => ['admin', 'incident_manager'],
        'two_factor_challenge_minutes' => 5,
        'two_factor_recovery_codes' => 8,
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | SLA
    |--------------------------------------------------------------------------
    |
    | Default targets in minutes per severity, used when no row exists in
    | sla_policies. Clocks run 24/7 (calendar time, not business hours).
    | A warning is raised once this share of a target has elapsed.
    |
    */

    'sla' => [
        'defaults' => [
            1 => ['ack' => 5, 'mitigate' => 30, 'resolve' => 4 * 60],
            2 => ['ack' => 15, 'mitigate' => 60, 'resolve' => 8 * 60],
            3 => ['ack' => 60, 'mitigate' => 4 * 60, 'resolve' => 3 * 24 * 60],
            4 => ['ack' => 4 * 60, 'mitigate' => 24 * 60, 'resolve' => 7 * 24 * 60],
        ],
        'warning_ratio' => 0.8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | Incidents that match no routing rule (or whose service has no owner team)
    | go to this team.
    |
    */

    'routing' => [
        'default_team' => env('INCIDENTS_DEFAULT_TEAM', 'NOC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        // A private disk; files are never served from a public URL.
        'disk' => env('INCIDENTS_UPLOAD_DISK', 'local'),
        // Minutes a download link stays valid.
        'download_link_minutes' => 5,
        // 'none' stores files unscanned. Add a VirusScanner implementation (e.g. ClamAV) to enable.
        'virus_scanner' => env('INCIDENTS_VIRUS_SCANNER', 'none'),
        'max_kb' => 25 * 1024,
        'allowed_extensions' => $uploadExtensions,
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser push
    |--------------------------------------------------------------------------
    |
    | Web Push needs a VAPID key pair. Generate one with:
    | php artisan incidents:vapid-keys  (or any Web Push key generator). Without keys,
    | the bell and email still work; push is simply unavailable.
    |
    */

    'push' => [
        'vapid_subject' => env('VAPID_SUBJECT', 'mailto:noc@foxdale.local'),
        'vapid_public_key' => env('VAPID_PUBLIC_KEY'),
        'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    |
    | The provider key is read from ANTHROPIC_API_KEY and never stored in the
    | database. Feature switches can be overridden in /admin/settings.
    |
    */

    'ai' => [
        'enabled' => (bool) env('AI_ENABLED', false),
        'provider' => env('AI_PROVIDER', 'claude'),
        'model' => env('AI_MODEL'),
        'timeout' => 30,
        'redact' => ['emails', 'phones', 'card_numbers', 'tokens', 'ip_addresses'],
        'features' => [
            'summary' => true, 'severity' => true, 'routing' => true,
            'similar' => true, 'draft_update' => true, 'draft_rca' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime settings
    |--------------------------------------------------------------------------
    |
    | Keys that admins can change through /admin/settings, with their defaults.
    | A stored value must have the same type as its default.
    |
    */

    'settings' => [
        'display_timezone' => env('INCIDENTS_DISPLAY_TIMEZONE', 'UTC'),
        'uploads.max_kb' => 25 * 1024,
        'uploads.allowed_extensions' => $uploadExtensions,
        'rca.due_business_days' => 5,
        'ai.enabled' => (bool) env('AI_ENABLED', false),
        'ai.features.summary' => true,
        'ai.features.severity' => true,
        'ai.features.routing' => true,
        'ai.features.similar' => true,
        'ai.features.draft_update' => true,
        'ai.features.draft_rca' => true,
        'channels.mattermost.enabled' => false,
        'channels.whatsapp.enabled' => false,
    ],

];
