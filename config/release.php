<?php

return [
    'version' => env('APP_VERSION', '2.99.0'),

    /*
    |--------------------------------------------------------------------------
    | Release Type
    |--------------------------------------------------------------------------
    |
    | Supported values: major, minor, patch, critical_fix, security, hotfix,
    | maintenance, initial.
    |
    */
    'type' => env('APP_RELEASE_TYPE', 'minor'),

    'date' => env('APP_RELEASE_DATE', '2026-10-22'),

    'commit' => env('SOURCE_COMMIT')
        ?: env('COOLIFY_GIT_COMMIT_SHA')
        ?: env('GIT_COMMIT')
        ?: null,

    /*
    |--------------------------------------------------------------------------
    | Deployment Identity
    |--------------------------------------------------------------------------
    |
    | The Vite build writes an artifact identity that combines the Git/platform
    | commit when available with deterministic source and built-asset hashes.
    | These paths are deliberately configuration values rather than extra
    | optional environment variables, keeping production env validation quiet
    | while still allowing tests or custom providers to override them.
    |
    */
    'deployment_id' => null,

    'deployment_built_at' => null,

    'deployment_manifest' => public_path('build/deployment.json'),

    'asset_manifest' => public_path('build/manifest.json'),
];
