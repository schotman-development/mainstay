<?php

namespace Mainstay\Tests;

use Mainstay\Mainstay;
use Mainstay\MainstayServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MainstayServiceProvider::class];
    }

    #[Test]
    public function it_mounts_the_admin_panel_at_the_configured_path(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('id="mainstay-editor"', escape: false)
            ->assertSee('aria-label="Sections"', escape: false);
    }

    #[Test]
    public function it_hands_unknown_admin_paths_to_the_client_side_router(): void
    {
        $this->get('/admin/collections/posts')->assertOk();
    }

    #[Test]
    public function it_serves_the_content_api_under_its_own_prefix(): void
    {
        $this->getJson('api/mainstay')
            ->assertOk()
            ->assertExactJson([
                'name' => 'mainstay',
                'version' => Mainstay::VERSION,
            ]);
    }

    #[Test]
    public function it_resolves_mainstay_as_a_singleton(): void
    {
        $this->assertSame($this->app->make(Mainstay::class), $this->app->make(Mainstay::class));
    }
}
