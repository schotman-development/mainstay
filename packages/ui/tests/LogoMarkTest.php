<?php

namespace Mainstay\Ui\Tests;

use Illuminate\Support\Facades\Blade;
use Mainstay\Ui\UiServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LogoMarkTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [UiServiceProvider::class];
    }

    #[Test]
    public function the_mark_is_decorative_unless_it_is_given_a_label(): void
    {
        $this->assertStringContainsString('aria-hidden="true"', Blade::render('<x-mainstay::logo-mark />'));
        $this->assertStringContainsString('role="img"', Blade::render('<x-mainstay::logo-mark label="Mainstay" />'));
    }
}
