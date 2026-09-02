<?php

namespace Tests\Unit;

use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\SingBox;
use App\Protocols\Surfboard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientUserAgentRecognitionTest extends TestCase
{
    #[DataProvider('userAgents')]
    public function test_client_user_agent_matches_expected_subscription_template(
        string $userAgent,
        string $expectedProtocol
    ): void {
        $actual = app('protocols.manager')->matchProtocolClassName(strtolower($userAgent));

        $this->assertSame($expectedProtocol, $actual, $userAgent);
    }

    public static function userAgents(): array
    {
        return [
            'clash party current' => ['mihomo.party/v1.9.5 (clash.meta)', ClashMeta::class],
            'mihomo party legacy' => ['Mihomo Party/1.8.5', ClashMeta::class],
            'clash party product name' => ['Clash-Party/2.0.0', ClashMeta::class],
            'clash verge stable' => ['clash-verge/v2.4.3', ClashMeta::class],
            'clash verge autobuild' => ['clash-verge/v2.4.3+autobuild.0830.92d9c94', ClashMeta::class],
            'flclash' => ['FlClash/0.8.92', ClashMeta::class],
            'clash mi current combined' => ['ClashMeta/1.19.28; mihomo/1.19.28', ClashMeta::class],
            'mihomo core only' => ['mihomo/1.19.28', ClashMeta::class],
            'clash mi product name' => ['ClashMi/1.0.26', ClashMeta::class],
            'clash meta android' => ['ClashMetaForAndroid/2.11.16.Meta', ClashMeta::class],
            'clash meta android separated' => ['Clash-Meta-For-Android/2.11.16', ClashMeta::class],
            'hiddify' => ['Hiddify/2.5.7', SingBox::class],
            'hiddify next' => ['HiddifyNext/2.5.7', SingBox::class],
            'hiddify app' => ['HiddifyApp/2.5.7', SingBox::class],
            'surfboard' => ['Surfboard/2.24.6 (Android 15)', Surfboard::class],
            'plain clash regression' => ['clash/1.18.0', Clash::class],
        ];
    }
}
