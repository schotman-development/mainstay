<?php

namespace Mainstay\Ui\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Mainstay\Ui\Demo\Entries;
use Mainstay\Ui\UiServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/*
 | The seam. Each behaviour in src/js finds its markup by a data attribute, and
 | the attribute is written in a Blade file the JavaScript never sees: a rename
 | on either side leaves both halves passing their own tests and the component
 | quietly inert in the browser.
 |
 | The vitest suites next to those modules test the logic against markup of their
 | own. This tests the only thing they cannot: that the real component still
 | emits what they assume.
 */
class BehaviourContractTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [UiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->make('view')->addNamespace('stories', __DIR__.'/../stories/blade');
    }

    public static function hooks(): array
    {
        return [
            'checkbox.ts' => ['<x-mainstay::checkbox label="Select all" indeterminate />', ['data-indeterminate']],
            'dropdown.ts' => ['<x-mainstay::dropdown><x-slot:label>New</x-slot:label><x-mainstay::dropdown-item>Page</x-mainstay::dropdown-item></x-mainstay::dropdown>', ['data-dropdown', '<summary', '<details']],
            'tag-input.ts' => ['<x-mainstay::tag-input name="tags" :tags="[\'editor\']" />', ['data-tag-input', 'data-tag-field', 'data-placeholder', 'data-tag-template', 'data-tag-label', 'data-tag-remove', 'data-tag ']],
            'command-center.ts' => ['<x-mainstay::command-center :commands="[]" />', ['data-command-center', 'data-command-field', 'data-command-list', 'data-command-empty', 'data-command-option', 'data-command-shortcut', 'data-command-label', 'data-command-group', 'data-commands']],
            'sidebar.ts' => ['<x-mainstay::sidebar :sections="[[\'items\' => [[\'href\' => \'/a\', \'label\' => \'A\', \'items\' => [[\'href\' => \'/a/b\', \'label\' => \'B\']]]]]]" current="/a" />', ['data-sidebar-toggle', 'aria-controls=', 'data-open=', 'data-inside=']],
        ];
    }

    #[Test]
    #[DataProvider('hooks')]
    public function the_component_emits_what_its_behaviour_looks_for(string $template, array $hooks): void
    {
        $html = Blade::render($template);

        foreach ($hooks as $hook) {
            $this->assertStringContainsString($hook, $html);
        }
    }

    /* The list screen's two counts read different things, and both are markup. */
    #[Test]
    public function the_entry_list_emits_its_selection_hooks(): void
    {
        $html = View::make('stories::components.page-list', ['entries' => Entries::pages(), 'label' => 'Pages'])->render();

        foreach ([
            'data-entry-list="Pages"',
            'data-entry-matched',
            'data-select-page',
            'data-select-row',
            'data-selection-bar',
            'data-selection-live',
            'data-selection-count',
            'data-selection-clear',
        ] as $hook) {
            $this->assertStringContainsString($hook, $html);
        }

        /* The paths the bar counts against are the ones the search left rather
           than the ones on this page. Decoded rather than pattern-matched,
           because what matters is the list it parses to. */
        preg_match('/data-entry-matched>(.*?)<\/script>/s', $html, $matched);

        $paths = json_decode(html_entity_decode($matched[1]), true);
        $expected = array_column(Entries::pages(), 'path');

        sort($paths);
        sort($expected);

        /* Membership, not order: the bar asks whether a selected path is still
           in the result, and the rows are sorted by whatever column the reader
           picked. */
        $this->assertSame($expected, $paths);
    }

    #[Test]
    public function the_entry_screen_emits_its_form_hooks(): void
    {
        $html = View::make('stories::components.entry-details', ['entry' => Entries::draft()])->render();

        foreach ([
            'data-dirty-form',
            'data-save-hint',
            'data-discard',
            'data-status',
            'data-status-value',
            'data-status-label',
            'data-status-option',
            'data-status-chip',
        ] as $hook) {
            $this->assertStringContainsString($hook, $html);
        }
    }

    /* The status control is a hidden input behind a disclosure, so the value the
       form posts has to be there whether or not anything is clicked. */
    #[Test]
    public function the_status_control_posts_a_value_without_being_touched(): void
    {
        $html = View::make('stories::components.entry-details', ['entry' => Entries::published()])->render();

        $this->assertMatchesRegularExpression('/<input type="hidden" name="status" value="Published" data-status-value>/', $html);
    }
}
