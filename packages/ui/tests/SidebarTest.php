<?php

namespace Mainstay\Ui\Tests;

use Illuminate\Support\Facades\Blade;
use Mainstay\Ui\UiServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SidebarTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [UiServiceProvider::class];
    }

    private array $sections = [
        ['label' => 'Content', 'items' => [
            ['href' => '/admin', 'label' => 'Overview'],
            ['href' => '/admin/pages', 'label' => 'Pages'],
        ]],
        ['label' => 'System', 'items' => [
            ['href' => '/admin/settings', 'label' => 'Settings'],
        ]],
    ];

    private array $tree = [
        ['items' => [[
            'href' => '/admin/collections',
            'label' => 'Collections',
            'items' => [[
                'href' => '/admin/collections/posts',
                'label' => 'Posts',
                'submenu' => 'flyout',
                'items' => [
                    ['href' => '/admin/collections/posts', 'label' => 'View posts'],
                    ['href' => '/admin/collections/posts/new', 'label' => 'New post'],
                ],
            ]],
        ]]],
    ];

    private function render(array $sections, ?string $current = null): string
    {
        return Blade::render(
            '<x-mainstay::sidebar :sections="$sections" :current="$current" />',
            compact('sections', 'current'),
        );
    }

    private function occurrences(string $pattern, string $html): int
    {
        return preg_match_all($pattern, $html);
    }

    #[Test]
    public function exactly_one_link_carries_aria_current(): void
    {
        $html = $this->render($this->sections, '/admin/pages/42');

        $this->assertSame(1, $this->occurrences('/aria-current="page"/', $html));
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*href="\/admin\/pages"/', $html);
    }

    #[Test]
    public function two_items_on_the_same_route_mark_one_link_not_both(): void
    {
        $pinned = array_merge(
            [['label' => 'Pinned', 'items' => [['href' => '/admin/pages', 'label' => 'Pages']]]],
            $this->sections,
        );

        $this->assertSame(1, $this->occurrences('/aria-current="page"/', $this->render($pinned, '/admin/pages')));
    }

    #[Test]
    public function each_group_labels_its_own_list(): void
    {
        $html = $this->render($this->sections);

        preg_match_all('/<h2 id="([^"]+)"/', $html, $matches);

        $this->assertCount(2, $matches[1]);

        foreach ($matches[1] as $heading) {
            $this->assertStringContainsString('aria-labelledby="'.$heading.'"', $html);
        }
    }

    /*
     | Collections is a toggle, so it is the only disclosure in the tree, and it
     | is pinned open the whole way down to the page being viewed.
     */
    #[Test]
    public function being_inside_a_collection_opens_it_in_place_of_its_flyout(): void
    {
        $html = $this->render($this->tree, '/admin/collections/posts/42');

        $this->assertSame(1, $this->occurrences('/aria-expanded="true"/', $html));
        $this->assertStringNotContainsString('invisible', $html);
        $this->assertStringNotContainsString('data-open="false"', $html);
    }

    /*
     | Its own list goes behind display:none, which takes the collection flyout
     | nested inside it out of reach along with it.
     */
    #[Test]
    public function a_shut_toggle_hides_its_list_rather_than_flying_it_out(): void
    {
        $html = $this->render($this->tree, '/admin/pages');

        $this->assertMatchesRegularExpression('/<ul\s+id="[^"]+"\s+data-open="false"\s+class="[^"]*data-\[open=false\]:hidden"/s', $html);
        $this->assertStringNotContainsString('aria-expanded="true"', $html);
    }

    /* Collections shut takes its collections down with it, so the flyout only
       has to be checked with the parent open -- which is the state it is
       reached in. */
    #[Test]
    public function a_collection_carries_a_flyout_and_no_disclosure_of_its_own(): void
    {
        $html = $this->render($this->tree, '/admin/collections');

        $this->assertSame(1, $this->occurrences('/invisible/', $html));
        $this->assertSame(1, $this->occurrences('/aria-expanded=/', $html));
        $this->assertMatchesRegularExpression('/invisible[^"]*absolute[^"]*left-full/', $html);
    }

    /*
     | Landing anywhere in a section opens it, and it stays closeable from there
     | -- a row that is itself the disclosure cannot be a row that does nothing
     | when clicked.
     */
    #[Test]
    public function the_section_you_are_in_is_open_and_never_taken_out_of_use(): void
    {
        foreach (['/admin/collections', '/admin/collections/posts/new'] as $current) {
            $html = $this->render($this->tree, $current);

            $this->assertStringContainsString('aria-expanded="true"', $html);
            $this->assertDoesNotMatchRegularExpression('/<button[^>]*\sdisabled/', $html);
        }
    }

    /*
     | The disclosure is the row, not a control parked at the end of it: the
     | label is inside the button, and the button is the only thing on the row.
     */
    #[Test]
    public function a_toggle_row_is_one_button_carrying_the_whole_item(): void
    {
        $html = $this->render($this->tree, '/admin/pages');

        $this->assertMatchesRegularExpression('/<button[^>]*class="[^"]*w-full[^"]*"[^>]*>(?:(?!<\/button>).)*Collections/s', $html);
        $this->assertSame(1, $this->occurrences('/<button/', $html));
        $this->assertStringNotContainsString('href="/admin/collections"', $html);
    }

    /*
     | Each level steps in less than the one before it, and the third gets a
     | rule as well as the smaller type -- three things saying the same thing,
     | because indentation alone stops being readable by the time it is this
     | shallow.
     */
    #[Test]
    public function third_level_items_step_in_behind_a_rule_and_drop_a_size(): void
    {
        $html = $this->render($this->tree, '/admin/collections/posts/new');

        $this->assertStringContainsString('class="mt-1 ml-3.5 flex flex-col gap-1 pl-2 data-[open=false]:hidden"', $html);
        $this->assertStringContainsString('class="mt-1 ml-2 flex flex-col gap-1 border-l border-border pl-2"', $html);

        $this->assertMatchesRegularExpression('/<a[^>]*\btext-xs\b[^>]*>View posts</', $html);
        $this->assertMatchesRegularExpression('/<a[^>]*\btext-xs\b[^>]*>New post</', $html);
        $this->assertMatchesRegularExpression('/<a[^>]*\btext-sm\b[^>]*>Posts</', $html);

        /* The rule is the third level's alone: the second still has none. */
        $this->assertSame(1, $this->occurrences('/border-l\b/', $html));
    }
}
