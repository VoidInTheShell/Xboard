<?php

namespace App\Support;

class ClientCatalogDefaults
{
    public static function all(): array
    {
        $desktopScopes = static function (int $order): array {
            return collect(['windows', 'mac-intel', 'mac-apple-silicon', 'linux'])
                ->map(fn(string $platform) => [
                    'device_type' => 'desktop',
                    'platform' => $platform,
                    'sort_order' => $order,
                ])
                ->all();
        };

        return [
            [
                'slug' => 'clash-party',
                'name' => 'Clash Party',
                'description' => '原 Mihomo Party。面向桌面端的 Mihomo 图形客户端，适合日常规则分流与多订阅管理。',
                'logo_mode' => 'url',
                'logo_url' => 'https://github.com/mihomo-party-org.png?size=160',
                'tags' => ['推荐', '原 Mihomo Party'],
                'download_url' => 'https://github.com/mihomo-party-org/clash-party/releases',
                'docs_url' => 'https://clashparty.org/',
                'quick_import_enabled' => true,
                'quick_import_url' => 'clash://install-config?url={url}&name={name}',
                'subscription_template' => 'clashmeta',
                'is_builtin' => true,
                'scopes' => $desktopScopes(10),
            ],
            [
                'slug' => 'clash-verge',
                'name' => 'Clash Verge',
                'description' => '跨平台 Mihomo 桌面客户端，适合 Windows、macOS 与 Linux 用户。',
                'logo_mode' => 'url',
                'logo_url' => 'https://github.com/clash-verge-rev.png?size=160',
                'tags' => ['桌面端', 'Mihomo'],
                'download_url' => 'https://github.com/clash-verge-rev/clash-verge-rev/releases',
                'docs_url' => 'https://clash-verge-rev.github.io/',
                'quick_import_enabled' => true,
                'quick_import_url' => 'clash://install-config?url={url}&name={name}',
                'subscription_template' => 'clashmeta',
                'is_builtin' => true,
                'scopes' => $desktopScopes(20),
            ],
            [
                'slug' => 'flclash',
                'name' => 'FlClash',
                'description' => '基于 Flutter 的跨平台 Clash Meta 客户端，同一套界面覆盖桌面端与 Android。',
                'logo_mode' => 'url',
                'logo_url' => 'https://github.com/chen08209.png?size=160',
                'tags' => ['跨平台', 'Android'],
                'download_url' => 'https://github.com/chen08209/FlClash/releases',
                'docs_url' => 'https://github.com/chen08209/FlClash',
                'quick_import_enabled' => true,
                'quick_import_url' => 'clash://install-config?url={url}&name={name}',
                'subscription_template' => 'clashmeta',
                'is_builtin' => true,
                'scopes' => array_merge($desktopScopes(30), [[
                    'device_type' => 'mobile',
                    'platform' => 'android',
                    'sort_order' => 10,
                ]]),
            ],
            [
                'slug' => 'clash-mi',
                'name' => 'Clash Mi',
                'description' => '基于 Flutter 与 Mihomo 的现代客户端，本目录默认提供 Android 入口。',
                'logo_mode' => 'url',
                'logo_url' => 'https://github.com/KaringX.png?size=160',
                'tags' => ['Android', 'Mihomo'],
                'download_url' => 'https://github.com/KaringX/clashmi/releases',
                'docs_url' => 'https://clashmi.app/',
                'quick_import_enabled' => true,
                'quick_import_url' => 'clash://install-config?url={url}&name={name}',
                'subscription_template' => 'clashmeta',
                'is_builtin' => true,
                'scopes' => [[
                    'device_type' => 'mobile',
                    'platform' => 'android',
                    'sort_order' => 20,
                ]],
            ],
            [
                'slug' => 'clash-meta-android',
                'name' => 'Clash Meta',
                'description' => 'MetaCubeX 提供的 Android 图形客户端，面向 Clash Meta 配置与规则体系。',
                'logo_mode' => 'url',
                'logo_url' => 'https://github.com/MetaCubeX.png?size=160',
                'tags' => ['Android', 'Clash Meta'],
                'download_url' => 'https://github.com/MetaCubeX/ClashMetaForAndroid/releases',
                'docs_url' => 'https://github.com/MetaCubeX/ClashMetaForAndroid',
                'quick_import_enabled' => true,
                'quick_import_url' => 'clashmeta://install-config?url={url}&name={name}',
                'subscription_template' => 'clashmeta',
                'is_builtin' => true,
                'scopes' => [[
                    'device_type' => 'mobile',
                    'platform' => 'android',
                    'sort_order' => 30,
                ]],
            ],
            [
                'slug' => 'hiddify',
                'name' => 'Hiddify',
                'description' => '界面简洁的多协议客户端，默认使用 Sing-box 订阅模板。',
                'logo_mode' => 'url',
                'logo_url' => 'https://github.com/hiddify.png?size=160',
                'tags' => ['Android', 'Sing-box'],
                'download_url' => 'https://github.com/hiddify/hiddify-app/releases',
                'docs_url' => 'https://hiddify.com/',
                'quick_import_enabled' => false,
                'quick_import_url' => null,
                'subscription_template' => 'singbox',
                'is_builtin' => true,
                'scopes' => [[
                    'device_type' => 'mobile',
                    'platform' => 'android',
                    'sort_order' => 40,
                ]],
            ],
            [
                'slug' => 'surfboard',
                'name' => 'Surfboard',
                'description' => '面向 Android 的规则代理客户端，适合使用 Surfboard 配置模板的用户。',
                'logo_mode' => 'url',
                'logo_url' => 'https://github.com/getsurfboard.png?size=160',
                'tags' => ['Android', 'Surfboard'],
                'download_url' => 'https://github.com/getsurfboard/surfboard/releases',
                'docs_url' => 'https://getsurfboard.com/',
                'quick_import_enabled' => false,
                'quick_import_url' => null,
                'subscription_template' => 'surfboard',
                'is_builtin' => true,
                'scopes' => [[
                    'device_type' => 'mobile',
                    'platform' => 'android',
                    'sort_order' => 50,
                ]],
            ],
        ];
    }
}
