<?php

namespace Tests\Feature;

use App\Models\SubscribeTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertStringContainsString('fake-ip-filter', SubscribeTemplate::getContent('clash'));
        $this->assertSame(SubscribeTemplate::getContent('clash'), SubscribeTemplate::getContent('clashmeta'));
    }

    public function test_blank_saved_template_restores_default_and_custom_content_wins(): void
    {
        SubscribeTemplate::setContent('surge', '');
        $this->assertStringContainsString('[General]', SubscribeTemplate::getContent('surge'));
        SubscribeTemplate::setContent('surge', '[General]' . "\n" . 'custom = true');
        $this->assertSame('[General]' . "\n" . 'custom = true', SubscribeTemplate::getContent('surge'));
    }
}
