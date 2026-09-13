<?php

use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

return [

    'default' => env('LOG_CHANNEL', 'daily'),

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily'],
            'ignore_exceptions' => false,
        ],

        'backup' => [
            'driver' => 'monolog',
            'handler' => \App\Services\Logs\PanelLogHandler::class,
            'with' => ['category' => 'backup'],
            'path' => storage_path('logs/managed-backup.log'),
            'level' => 'debug',
        ],

        'single' => [
            'driver' => 'monolog',
            'handler' => \App\Services\Logs\PanelLogHandler::class,
            'with' => ['category' => 'app'],
            'path' => storage_path('logs/managed-app.log'),
            'level' => 'debug',
        ],

        'daily' => [
            'driver' => 'monolog',
            'handler' => \App\Services\Logs\PanelLogHandler::class,
            'with' => ['category' => 'app'],
            'path' => storage_path('logs/managed-app.log'),
            'level' => 'debug',
        ],

        'stderr' => [
            'tap' => [\App\Services\Logs\LogPolicyTap::class],
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'tap' => [\App\Services\Logs\LogPolicyTap::class],
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'errorlog' => [
            'tap' => [\App\Services\Logs\LogPolicyTap::class],
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'deprecations' => [
            'driver' => 'monolog',
            'handler' => \App\Services\Logs\PanelLogHandler::class,
            'with' => ['category' => 'deprecation'],
            'path' => storage_path('logs/managed-deprecation.log'),
            'level' => 'debug',
        ],
    ],

];
