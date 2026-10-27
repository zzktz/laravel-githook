<?php

return [
    'enabled' => env('GITHOOK_ENABLED', false),

    'route' => [
        'prefix' => env('GITHOOK_ROUTE_PREFIX', 'githook'),
        'uri' => env('GITHOOK_ROUTE_URI', 'webhook'),
        'middleware' => ['api'],
    ],

    'secret' => env('GITHOOK_SECRET'),
    'allowed_events' => ['push hook'],
    'allowed_ips' => [],
    'max_payload_bytes' => 1048576,

    # 每个配置项对应一个 Gitee 仓库及服务器上的工作目录。
    # 仓库单独配置的密钥优先于上方的全局密钥。
    'repositories' => [],

    'deployment' => [
        'require_clean_worktree' => true,
        'lock_path' => storage_path('framework/cache/laravel-githook'),
        'timeout' => 120,
        'commands' => [],
        'health_check' => [
            'url' => null,
            'timeout' => 5,
        ],
    ],

    'logging' => [
        'channel' => env('GITHOOK_LOG_CHANNEL', 'stack'),
    ],
];
