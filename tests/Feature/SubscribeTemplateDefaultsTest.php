<?php

namespace Tests\Feature;

use App\Models\SubscribeTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SubscribeTemplateDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        $this->app['cache']->forgetDriver('redis');
    }

    public function test_all_six_formats_have_built_in_fallback_templates(): void
    {
        foreach (['singbox', 'clash', 'clashmeta', 'stash', 'surge', 'surfboard'] as $name) {
            $this->assertNotEmpty(SubscribeTemplate::getContent($name), "missing {$name} default");
        }
        $clash = SubscribeTemplate::getContent('clash');
        $clashMeta = SubscribeTemplate::getContent('clashmeta');

        $this->assertStringContainsString('fake-ip-filter', $clash);
        $this->assertStringContainsString('fake-ip-filter', $clashMeta);
        $this->assertNotSame($clash, $clashMeta);
        $this->assertStringNotContainsString('proxy-providers:', $clash);
        $this->assertStringNotContainsString('proxy-providers:', $clashMeta);
        $this->assertStringContainsString('GEOSITE,', $clash);
        $this->assertStringContainsString('GEOSITE,', $clashMeta);
        $this->assertStringContainsString('name: 故障转移', $clash);
        $this->assertStringContainsString('name: 故障转移', $clashMeta);
    }

    public function test_blank_saved_template_restores_default_and_custom_content_wins(): void
    {
        SubscribeTemplate::setContent('surge', '');
        $this->assertStringContainsString('[General]', SubscribeTemplate::getContent('surge'));
        SubscribeTemplate::setContent('surge', '[General]' . "\n" . 'custom = true');
        $this->assertSame('[General]' . "\n" . 'custom = true', SubscribeTemplate::getContent('surge'));
    }

    public function test_clash_defaults_parse_and_keep_panel_node_injection_slots(): void
    {
        $clash = SubscribeTemplate::getContent('clash');
        $clashMeta = SubscribeTemplate::getContent('clashmeta');

        foreach (['clash', 'clashmeta'] as $name) {
            $config = Yaml::parse(SubscribeTemplate::getContent($name));

            $this->assertIsArray($config);
            $this->assertSame([], $config['proxies']);
            $this->assertCount(36, $config['proxy-groups']);
            $this->assertArrayNotHasKey('proxy-providers', $config);
            foreach ($config['proxy-groups'] as $group) {
                $this->assertArrayNotHasKey('use', $group);
                $this->assertArrayNotHasKey('filter', $group);
                $this->assertArrayNotHasKey('include-all', $group);
            }
        }

        $clashConfig = Yaml::parse($clash);
        $clashMetaConfig = Yaml::parse($clashMeta);
        $this->assertSame($clashMetaConfig['dns'], $clashConfig['dns']);
        $this->assertSame($clashMetaConfig['rules'], $clashConfig['rules']);
        $this->assertSame($clashMetaConfig['rule-anchor'], $clashConfig['rule-anchor']);
        $this->assertSame($clashMetaConfig['rule-providers'], $clashConfig['rule-providers']);
        $this->assertNotEmpty(array_filter(
            $clashConfig['rules'],
            fn($rule) => str_starts_with($rule, 'GEOSITE,')
        ));
        $this->assertArrayHasKey('format', $clashConfig['rule-providers']['cn_domain']);
        $this->assertSame('mrs', $clashConfig['rule-providers']['cn_domain']['format']);
        $this->assertArrayHasKey('icon', $clashMetaConfig['proxy-groups'][0]);
        $this->assertArrayNotHasKey('icon', $clashConfig['proxy-groups'][0]);
    }
}
