<?php

namespace Mainstay\Ui\Tests;

use Illuminate\Support\Facades\View;
use Mainstay\Ui\Demo\Entries;
use Mainstay\Ui\EntryForm;
use Mainstay\Ui\UiServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EntryDetailsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [UiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->make('view')->addNamespace('stories', __DIR__.'/../stories/blade');
    }

    private function screen(array $entry): string
    {
        return View::make('stories::components.entry-details', ['entry' => $entry])->render();
    }

    #[Test]
    public function an_edit_to_any_field_reads_as_unsaved(): void
    {
        $draft = Entries::draft();

        $this->assertFalse(EntryForm::changed($draft, $draft));
        $this->assertTrue(EntryForm::changed($draft, [...$draft, 'excerpt' => 'x']));
        $this->assertTrue(EntryForm::changed($draft, [...$draft, 'released' => '2026-01-01']));
        $this->assertTrue(EntryForm::changed($draft, [...$draft, 'seoDescription' => 'x']));
    }

    #[Test]
    public function an_equal_tag_list_is_not_an_edit_a_different_one_is(): void
    {
        $draft = Entries::draft();

        $this->assertFalse(EntryForm::changed($draft, [...$draft, 'tags' => array_values($draft['tags'])]));
        $this->assertTrue(EntryForm::changed($draft, [...$draft, 'tags' => array_slice($draft['tags'], 0, -1)]));
        $this->assertTrue(EntryForm::changed($draft, [...$draft, 'tags' => array_reverse($draft['tags'])]));
    }

    #[Test]
    public function a_field_set_for_the_first_time_counts_as_an_edit(): void
    {
        $blank = Entries::blank();

        $this->assertTrue(EntryForm::changed($blank, [...$blank, 'thumbnail' => 'preview.png']));
    }

    #[Test]
    public function the_server_touching_modified_does_not(): void
    {
        $draft = Entries::draft();

        $this->assertFalse(EntryForm::changed($draft, [...$draft, 'modified' => '2030-01-01']));
    }

    /* The block editor is on the site, not here. */
    #[Test]
    public function there_is_nowhere_to_write_the_body_here(): void
    {
        $html = $this->screen(Entries::draft());

        $this->assertStringNotContainsString('contenteditable', $html);
        $this->assertStringNotContainsString('role="textbox"', $html);
        $this->assertStringContainsString(Entries::draft()['path'].'?edit=1', $html);
        $this->assertStringContainsString('Edit content', $html);
    }

    #[Test]
    public function an_empty_entry_is_invited_to_start_rather_than_told_it_is_broken(): void
    {
        $html = $this->screen(Entries::blank());

        $this->assertStringContainsString('Start writing', $html);
        $this->assertStringContainsString('Nothing written yet', $html);
    }

    #[Test]
    public function the_rail_dropdown_is_named_by_its_field_not_just_its_value(): void
    {
        /* The value is in a span of its own now, because the bundle retitles it
           when the rail changes -- but the field name still leads it. */
        $this->assertStringContainsString('<span class="sr-only">Status: </span><span data-status-label>Draft</span>', $this->screen(Entries::draft()));
    }

    /* A hint a screen reader never reaches is a hint only some people get. */
    #[Test]
    public function hints_are_reachable_not_just_visible(): void
    {
        $html = $this->screen(Entries::draft());

        $this->assertMatchesRegularExpression('/aria-describedby="entry-excerpt-hint"/', $html);
        $this->assertMatchesRegularExpression('/<p id="entry-excerpt-hint"[^>]*>\s*listed, quoted or shared/s', preg_replace('/Shown wherever this entry is /', '', $html));
    }

    /* Over the limit is information, not an error: the snippet gets truncated,
       nothing breaks. So it colours and never blocks. */
    #[Test]
    public function the_meta_description_counter_warns_past_the_limit_without_stopping_you(): void
    {
        $long = [...Entries::draft(), 'seoDescription' => str_repeat('a', 200)];
        $html = $this->screen($long);

        $this->assertStringContainsString('text-danger', $html);
        $this->assertStringContainsString('200/160', $html);
        $this->assertStringNotContainsString('maxlength', $html);
    }

    #[Test]
    public function there_is_exactly_one_breadcrumb_and_the_shell_owns_it(): void
    {
        $html = $this->screen(Entries::draft());

        $this->assertSame(1, preg_match_all('/aria-label="Breadcrumb"/', $html));
    }

    #[Test]
    public function the_last_crumb_is_the_current_page_and_is_not_a_link(): void
    {
        $html = $this->screen(Entries::draft());
        $trail = substr($html, strpos($html, 'aria-label="Breadcrumb"'));
        $trail = substr($trail, 0, strpos($trail, '</nav>'));

        $this->assertMatchesRegularExpression('/<span aria-current="page"[^>]*>Shipping the new editor<\/span>/', $trail);
        $this->assertStringNotContainsString('<a href="#">', $trail);
    }
}
