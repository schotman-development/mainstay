<?php

namespace Mainstay\Ui\Tests;

use Mainstay\Ui\Control;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ControlTest extends TestCase
{
    /** @return list<string> */
    private function classes(string $value): array
    {
        $classes = preg_split('/\s+/', trim($value)) ?: [];
        sort($classes);

        return array_values(array_filter($classes));
    }

    #[Test]
    public function bare_drops_the_frame_and_the_spacing_the_shell_provides(): void
    {
        $bare = Control::classes(bare: true);

        $this->assertStringNotContainsString('border-border', $bare);
        $this->assertStringNotContainsString('py-1.5', $bare);
        $this->assertStringNotContainsString('px-2.5', $bare);
        $this->assertStringContainsString('bg-transparent', $bare);
    }

    /* The shell draws the ring for the group; a control drawing its own inside
       it gives you a box in a box and two focus rings. */
    #[Test]
    public function bare_leaves_the_focus_ring_to_the_shell_around_it(): void
    {
        $this->assertStringNotContainsString('focus-visible:outline-accent', Control::classes(bare: true));
        $this->assertStringContainsString('focus:outline-none', Control::classes(bare: true));
    }

    #[Test]
    public function ground_picks_the_colour_a_control_sits_on(): void
    {
        $this->assertStringContainsString('bg-canvas', Control::classes());
        $this->assertStringContainsString('bg-surface', Control::classes(ground: 'surface'));
        $this->assertStringNotContainsString('bg-canvas', Control::classes(ground: 'surface'));
    }

    /* w-full cannot be argued out of from the outside -- at equal specificity
       the cascade goes by stylesheet order -- so the base has to be asked not to
       claim the width. */
    #[Test]
    public function full_width_off_does_not_claim_the_width(): void
    {
        $this->assertStringContainsString('w-full', Control::classes());
        $this->assertStringNotContainsString('w-full', Control::classes(fullWidth: false));
    }

    #[Test]
    public function size_none_keeps_the_frame_and_drops_the_spacing(): void
    {
        $combobox = Control::classes(size: 'none');

        $this->assertStringNotContainsString('px-2.5', $combobox);
        $this->assertStringNotContainsString('py-1.5', $combobox);
        $this->assertStringContainsString('border-border', $combobox);
        $this->assertStringContainsString('focus-visible:outline-accent', $combobox);
    }

    /*
     | The exact class sets the real call sites had before they were moved onto
     | this function, carried over from the React suite. Order does not matter;
     | membership does. The caller's own classes are appended by the component's
     | attribute bag, so they are spelled out here the same way.
     */
    public static function callSites(): array
    {
        return [
            'the list search box' => [
                Control::classes(size: 'sm', ground: 'surface', fullWidth: false).' w-48',
                'w-48 rounded-control border border-border bg-surface px-2.5 py-1 text-sm placeholder:text-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
            ],
            'a form control' => [
                Control::classes(),
                'w-full rounded-control border border-border bg-canvas px-2.5 py-1.5 text-sm placeholder:text-muted/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
            ],
            'the slug input inside its prefix shell' => [
                Control::classes(bare: true).' min-w-0 rounded-r-control px-2.5 py-1.5 font-mono text-xs',
                'w-full min-w-0 rounded-r-control bg-transparent px-2.5 py-1.5 font-mono text-xs focus:outline-none',
            ],
            'the tag input inside its chip shell' => [
                Control::classes(bare: true, fullWidth: false).' min-w-24 flex-1 py-0.5 text-sm placeholder:text-muted/70',
                'min-w-24 flex-1 bg-transparent py-0.5 text-sm placeholder:text-muted/70 focus:outline-none',
            ],
            "the command centre's combobox" => [
                Control::classes(size: 'none').' h-6.5 pl-8 pr-12 text-xs text-ink placeholder:text-muted',
                'w-full rounded-control border border-border bg-canvas h-6.5 pl-8 pr-12 text-xs text-ink placeholder:text-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
            ],
        ];
    }

    #[Test]
    #[DataProvider('callSites')]
    public function it_keeps_the_classes_each_call_site_had_before(string $got, string $want): void
    {
        $this->assertSame($this->classes($want), $this->classes($got));
    }
}
