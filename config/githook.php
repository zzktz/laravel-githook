<?php

return [
    'enabled' => env('GITHOOK_ENABLED', false),

    'route' => [
        'prefix' => env('GITHOOK_ROUTE_PREFIX', 'githook'),
        'uri' => env('GITHOOK_ROUTE_URI', 'webhook'),
        'github_uri' => env('GITHOOK_GITHUB_ROUTE_URI', 'github'),
        'middleware' => ['api'],
    ],

    # 保留旧版 Gitee 配置，Gitee 专属配置优先使用 providers.gitee。
    'secret' => env('GITHOOK_SECRET'),
    'allowed_events' => ['push hook'],
    'allowed_ips' => [],
    'max_payload_bytes' => 1048576,

    'providers' => [
        'gitee' => [
            'enabled' => env('GITHOOK_GITEE_ENABLED', true),
            'secret' => env('GITHOOK_GITEE_SECRET', env('GITHOOK_SECRET')),
        ],
        'github' => [
            'enabled' => env('GITHOOK_GITHUB_ENABLED', false),
            'secret' => env('GITHOOK_GITHUB_SECRET'),
            'allowed_events' => ['push'],
            'allowed_ips' => [],
        ],
    ],

    # 每个配置项对应一个 Gitee 仓库及服务器上的工作目录。
    # 仓库单独配置的密钥优先于上方的全局密钥。
    'repositories' => [],

    'deployment' => [
        'require_clean_worktree' => true,
        'lock_path' => storage_path('framework/cache/laravel-githook'),
        'timeout' => 120,
        'commands' => [
            ['composer', 'install', '--no-interaction', '--prefer-dist'],
        ],
        'health_check' => [
            'url' => null,
            'timeout' => 5,
        ],
    ],

    'logging' => [
        'channel' => env('GITHOOK_LOG_CHANNEL', 'stack'),
    ],
];
