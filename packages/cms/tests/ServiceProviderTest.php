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

    /* What a signed-in user sees there is IdentityTest's. */
    #[Test]
    public function it_mounts_the_admin_panel_at_the_configured_path_behind_its_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/collections/posts')->assertRedirect('/admin/login');
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
