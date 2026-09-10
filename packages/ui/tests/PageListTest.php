<?php

namespace Mainstay\Ui\Tests;

use Illuminate\Support\Facades\View;
use Mainstay\Ui\Demo\Entries;
use Mainstay\Ui\UiServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/*
 | The screen's own toolbar is gone: its controls moved up into the fixed shell's
 | bar, beside the breadcrumb. Rendered rather than reasoned about, because
 | "there is only one heading" is exactly the kind of claim that quietly stops
 | being true the next time the layout moves.
 */
class PageListTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [UiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->make('view')->addNamespace('stories', __DIR__.'/../stories/blade');
    }

    private function screen(?array $entries = null): string
    {
        return View::make('stories::components.page-list', [
            'entries' => $entries ?? Entries::pages(),
            'label' => 'Pages',
        ])->render();
    }

    #[Test]
    public function the_list_has_no_heading_of_its_own_only_the_breadcrumb(): void
    {
        $html = $this->screen();

        $this->assertDoesNotMatchRegularExpression('/<h1[\s>]/', $html);
        $this->assertSame(1, preg_match_all('/aria-label="Breadcrumb"/', $html));

        /* The trail is what names the list now, so the last crumb has to be it. */
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>Pages</', $html);
    }

    /* The table lost the heading it was pointed at, so it carries the name
       itself rather than referring to one that is no longer there. */
    #[Test]
    public function the_table_is_still_named(): void
    {
        $html = $this->screen();
        $table = substr($html, strpos($html, '<table'), strpos($html, '>', strpos($html, '<table')) - strpos($html, '<table') + 1);

        $this->assertStringContainsString('aria-label="Pages"', $table);
        $this->assertStringNotContainsString('aria-labelledby', $table);
    }

    #[Test]
    public function the_search_and_the_filter_live_in_the_shell_bar(): void
    {
        $html = $this->screen();
        $bar = substr($html, 0, strpos($html, '<table'));

        $this->assertStringContainsString('aria-label="Search pages"', $bar);
        $this->assertStringContainsString('Status', $bar);
    }

    /* Nothing to narrow, so nothing to narrow it with -- but the way to add the
       first one stays. */
    #[Test]
    public function an_empty_list_keeps_the_bar_and_drops_the_filters(): void
    {
        $html = $this->screen([]);

        $this->assertStringContainsString('Nothing here yet', $html);
        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);
        $this->assertStringNotContainsString('aria-label="Search pages"', $html);
    }

    /* Sorting is a link now, so a sorted list is a URL rather than a state only
       the person who clicked can see. */
    #[Test]
    public function every_column_header_sorts_and_the_current_one_says_which_way(): void
    {
        $html = $this->screen();

        $this->assertSame(6, preg_match_all('/, sort<\/span>/', $html));
        $this->assertSame(1, preg_match_all('/aria-sort="/', $html));
        $this->assertStringContainsString('aria-sort="descending"', $html);
        $this->assertMatchesRegularExpression('/href="\?[^"]*field=title[^"]*"/', $html);
    }
}
