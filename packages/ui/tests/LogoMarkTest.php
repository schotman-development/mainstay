<?php

namespace Mainstay\Ui\Tests;

use Illuminate\Support\Facades\Blade;
use Mainstay\Ui\UiServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LogoMarkTest extends TestCase
{
    private const TRIANGLE = 'M60 24 22 100h76L60 24Z';

    protected function getPackageProviders($app): array
    {
        return [UiServiceProvider::class];
    }

    /** @return array{paths: list<string>, stroke: int} */
    private function drawn(int $size, bool $lockup = false): array
    {
        $svg = Blade::render(
            '<x-mainstay::logo-mark :size="$size" :lockup="$lockup" />',
            compact('size', 'lockup'),
        );

        preg_match_all('/<path d="([^"]+)"/', $svg, $paths);
        preg_match('/stroke-width="(\d+)"/', $svg, $stroke);

        return ['paths' => $paths[1], 'stroke' => (int) $stroke[1]];
    }

    /*
     | The four rungs the design specifies, asserted as exact path data rather
     | than path counts: the mast lengths and the 16px triangle's raised apex
     | are the fidelity, and a count-only check would not notice either
     | changing.
     */
    #[Test]
    public function sixty_four_is_the_full_construction(): void
    {
        $this->assertSame(['paths' => ['M60 24v76', self::TRIANGLE, 'M36 70h48'], 'stroke' => 10], $this->drawn(64));
    }

    #[Test]
    public function thirty_two_shortens_the_mast_to_clear_the_apex(): void
    {
        $this->assertSame(['paths' => ['M60 40v60', self::TRIANGLE, 'M36 70h48'], 'stroke' => 11], $this->drawn(32));
    }

    #[Test]
    public function twenty_four_shortens_the_mast_again_and_drops_the_spreader(): void
    {
        $this->assertSame(['paths' => ['M60 50v50', self::TRIANGLE], 'stroke' => 11], $this->drawn(24));
    }

    /* The size the top bar actually uses; it must not fall to the favicon form. */
    #[Test]
    public function eighteen_keeps_the_mast(): void
    {
        $this->assertSame(['paths' => ['M60 50v50', self::TRIANGLE], 'stroke' => 11], $this->drawn(18));
    }

    #[Test]
    public function sixteen_is_the_triangle_alone_apex_raised_so_the_counter_stays_open(): void
    {
        $this->assertSame(['paths' => ['M60 26 22 100h76L60 26Z'], 'stroke' => 13], $this->drawn(16));
    }

    /*
     | Both sides of every breakpoint. Without the lower half, a boundary moved
     | down -- < 32 becoming < 31 -- changes no design-specified size and passes
     | silently.
     */
    #[Test]
    public function each_breakpoint_switches_between_its_two_neighbours_and_nowhere_else(): void
    {
        $this->assertSame($this->drawn(16), $this->drawn(17));
        $this->assertNotSame($this->drawn(17), $this->drawn(18));
        $this->assertSame($this->drawn(24), $this->drawn(18));
        $this->assertSame($this->drawn(24), $this->drawn(31));
        $this->assertNotSame($this->drawn(31), $this->drawn(32));
        $this->assertSame($this->drawn(32), $this->drawn(39));
        $this->assertNotSame($this->drawn(39), $this->drawn(40));
        $this->assertSame($this->drawn(64), $this->drawn(40));
    }

    #[Test]
    public function the_mark_is_decorative_unless_it_is_given_a_label(): void
    {
        $this->assertStringContainsString('aria-hidden="true"', Blade::render('<x-mainstay::logo-mark />'));
        $this->assertStringContainsString('role="img"', Blade::render('<x-mainstay::logo-mark label="Mainstay" />'));
    }

    /*
     | The ladder is for a mark standing on its own. Beside the wordmark the mast
     | has to survive at any size, or the top bar shows a stray arrowhead next to
     | the word Mainstay.
     */
    #[Test]
    public function a_lockup_holds_the_twenty_four_geometry_however_small_it_is_drawn(): void
    {
        $this->assertSame($this->drawn(24), $this->drawn(16, lockup: true));
        $this->assertSame($this->drawn(24), $this->drawn(12, lockup: true));
        $this->assertSame($this->drawn(64), $this->drawn(64, lockup: true));
    }

    /* Standing alone, 16px is still the design's mast-less rung. */
    #[Test]
    public function the_floor_applies_only_inside_a_lockup(): void
    {
        $this->assertSame(['M60 26 22 100h76L60 26Z'], $this->drawn(16)['paths']);
    }
}
