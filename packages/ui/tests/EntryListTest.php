<?php

namespace Mainstay\Ui\Tests;

use Mainstay\Ui\Demo\Entries;
use Mainstay\Ui\EntryList;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EntryListTest extends TestCase
{
    private function view(array $patch = []): array
    {
        return $patch + ['query' => '', 'status' => 'All', 'field' => 'modified', 'direction' => 'desc', 'page' => 1, 'perPage' => 8];
    }

    /** @return list<string> */
    private function titles(array $patch = []): array
    {
        return array_column(EntryList::shape(Entries::pages(), $this->view($patch))['rows'], 'title');
    }

    /** @return list<string> the paths of the demo pages with these titles */
    private function paths(string ...$titles): array
    {
        return array_column(array_filter(Entries::pages(), fn (array $page) => in_array($page['title'], $titles, true)), 'path');
    }

    #[Test]
    public function it_opens_on_the_most_recently_modified(): void
    {
        $this->assertSame(['Home', 'About', 'Pricing', 'Careers', 'Terms of service'], $this->titles());
    }

    #[Test]
    public function it_sorts_by_any_column_both_ways(): void
    {
        $this->assertSame(['About', 'Careers', 'Home', 'Pricing', 'Terms of service'], $this->titles(['field' => 'title', 'direction' => 'asc']));
        $this->assertSame(['Terms of service', 'Pricing', 'Home', 'Careers', 'About'], $this->titles(['field' => 'title', 'direction' => 'desc']));
    }

    /*
     | Careers and Terms of service share a modified date. The order they keep is
     | the order they were given, which is how a caller controls tie-breaks.
     */
    #[Test]
    public function ties_keep_author_order(): void
    {
        $this->assertSame(['Careers', 'Terms of service'], array_slice($this->titles(['field' => 'modified', 'direction' => 'desc']), 3));
    }

    /* Drafts have no release date, and must not lead the list when sorting by it. */
    #[Test]
    public function missing_release_dates_sort_last_whichever_way_the_column_points(): void
    {
        $this->assertSame(['Careers', 'Home', 'About', 'Terms of service', 'Pricing'], $this->titles(['field' => 'released', 'direction' => 'desc']));
        $this->assertSame(['Home', 'About', 'Careers', 'Terms of service', 'Pricing'], $this->titles(['field' => 'released', 'direction' => 'asc']));
    }

    #[Test]
    public function search_covers_title_author_and_category_case_insensitively(): void
    {
        $this->assertSame(['Pricing'], $this->titles(['query' => 'PRIC']));
        $this->assertSame(['About', 'Terms of service'], $this->titles(['query' => 'grace']));
        $this->assertSame(['Home', 'About', 'Pricing'], $this->titles(['query' => 'marketing']));
        $this->assertSame(['Home'], $this->titles(['query' => '  home  ']));
        $this->assertSame([], $this->titles(['query' => 'nothing at all']));
    }

    #[Test]
    public function the_status_filter_narrows_to_one_state(): void
    {
        $this->assertSame(['Pricing', 'Terms of service'], $this->titles(['status' => 'Draft']));
        $this->assertSame(['Home', 'About', 'Careers'], $this->titles(['status' => 'Published']));
    }

    #[Test]
    public function filters_and_sorting_compose(): void
    {
        $this->assertSame(['About', 'Careers', 'Home'], $this->titles(['status' => 'Published', 'field' => 'title', 'direction' => 'asc']));
    }

    #[Test]
    public function it_pages_the_result_and_reports_where_it_is(): void
    {
        $first = EntryList::shape(Entries::pages(), $this->view(['perPage' => 2]));

        $this->assertSame(['Home', 'About'], array_column($first['rows'], 'title'));
        $this->assertSame([5, 1, 3, 0], [$first['total'], $first['page'], $first['pageCount'], $first['start']]);

        $last = EntryList::shape(Entries::pages(), $this->view(['perPage' => 2, 'page' => 3]));

        $this->assertSame(['Terms of service'], array_column($last['rows'], 'title'));
        $this->assertSame([3, 3, 4], [$last['page'], $last['pageCount'], $last['start']]);
    }

    /*
     | Narrowing the query shortens the result under a page the caller is already
     | holding, so an unclamped page renders an empty table with rows behind it.
     */
    #[Test]
    public function a_page_past_the_end_is_clamped_not_left_empty(): void
    {
        $shaped = EntryList::shape(Entries::pages(), $this->view(['perPage' => 2, 'page' => 99, 'status' => 'Draft']));

        $this->assertSame([2, 1, 1, 0], [$shaped['total'], $shaped['page'], $shaped['pageCount'], $shaped['start']]);
        $this->assertSame(['Pricing', 'Terms of service'], array_column($shaped['rows'], 'title'));
    }

    #[Test]
    public function an_empty_result_still_reports_one_page(): void
    {
        $shaped = EntryList::shape(Entries::pages(), $this->view(['query' => 'nothing', 'page' => 4]));

        $this->assertSame([0, 1, 1, 0], [$shaped['total'], $shaped['page'], $shaped['pageCount'], $shaped['start']]);
    }

    #[Test]
    public function the_header_checkbox_reflects_only_the_rows_on_screen(): void
    {
        $rows = EntryList::shape(Entries::pages(), $this->view(['perPage' => 2]))['rows'];

        $this->assertSame(['count' => 0, 'all' => false, 'some' => false], EntryList::selectionState($rows, []));
        $this->assertSame(['count' => 1, 'all' => false, 'some' => true], EntryList::selectionState($rows, $this->paths('Home')));
        $this->assertSame(['count' => 2, 'all' => true, 'some' => false], EntryList::selectionState($rows, $this->paths('Home', 'About')));
    }

    /*
     | Selection outlives paging, so a full page can sit inside a much larger
     | selection and the box must still read as full rather than partial.
     */
    #[Test]
    public function rows_selected_on_other_pages_do_not_make_this_one_partial(): void
    {
        $rows = EntryList::shape(Entries::pages(), $this->view(['perPage' => 2]))['rows'];
        $state = EntryList::selectionState($rows, $this->paths('Home', 'About', 'Careers'));

        $this->assertTrue($state['all']);
        $this->assertFalse($state['some']);
    }

    #[Test]
    public function an_empty_page_is_never_all_selected(): void
    {
        $this->assertSame(['count' => 0, 'all' => false, 'some' => false], EntryList::selectionState([], $this->paths('Home')));
    }

    /*
     | perPage and page arrive off a query string, and array_slice with a
     | negative length drops rows off the end rather than erroring, so a bad one
     | would return fewer rows than the footer claimed. Nothing here should ever
     | disagree with the row count.
     */
    #[Test]
    public function a_nonsensical_page_size_still_yields_a_coherent_page(): void
    {
        foreach ([0, -1, -3, 2.5, NAN] as $perPage) {
            $shaped = EntryList::shape(Entries::pages(), $this->view(['perPage' => $perPage]));

            $this->assertGreaterThan(0, count($shaped['rows']));
            $this->assertLessThanOrEqual($shaped['total'], $shaped['start'] + count($shaped['rows']));
        }

        /* Clamped to the smallest sane size rather than to "everything". */
        $tiny = EntryList::shape(Entries::pages(), $this->view(['perPage' => -1]));

        $this->assertSame([5, 1], [$tiny['pageCount'], $tiny['page']]);
        $this->assertCount(1, $tiny['rows']);
        $this->assertCount(2, EntryList::shape(Entries::pages(), $this->view(['perPage' => 2.5]))['rows']);
    }

    #[Test]
    public function a_fractional_or_unparseable_page_lands_on_a_real_one(): void
    {
        $fractional = EntryList::shape(Entries::pages(), $this->view(['perPage' => 2, 'page' => 2.5]));
        $this->assertSame([2, 2], [$fractional['page'], $fractional['start']]);

        $broken = EntryList::shape(Entries::pages(), $this->view(['perPage' => 2, 'page' => NAN]));
        $this->assertSame([1, 0], [$broken['page'], $broken['start']]);
    }

    /* A decomposed title and a precomposed query are the same word. */
    #[Test]
    public function search_folds_unicode_to_one_form(): void
    {
        if (! class_exists(\Normalizer::class)) {
            $this->markTestSkipped('Folding two forms of one accent together needs ext-intl.');
        }

        $accented = [[...Entries::pages()[0], 'title' => "Cafe\u{0301} notes", 'path' => '/cafe']];

        $this->assertCount(1, EntryList::shape($accented, $this->view(['query' => "caf\u{00e9}"]))['rows']);
        $this->assertCount(1, EntryList::shape($accented, $this->view(['query' => "cafe\u{0301}"]))['rows']);
    }

    /*
     | The bulk bar acts on the selection, so the selection it reports has to be
     | what the search left behind -- otherwise "Move to trash" reaches rows that
     | are not on screen and cannot be checked before the click.
     */
    #[Test]
    public function the_shaped_result_carries_what_the_search_matched_not_just_the_page(): void
    {
        $shaped = EntryList::shape(Entries::pages(), $this->view(['perPage' => 2]));

        $this->assertCount(5, $shaped['matched']);
        $this->assertCount(2, $shaped['rows']);

        $searched = EntryList::shape(Entries::pages(), $this->view(['query' => 'careers']));

        $this->assertSame(['Careers'], array_column($searched['matched'], 'title'));
    }
}
