<?php

namespace Mainstay\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Mainstay\Facades\Mainstay;
use Mainstay\Tests\Fixtures\Article;
use Mainstay\Tests\Fixtures\Post;
use Mainstay\Tests\Fixtures\SiteSettings;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

/*
 | The phase 3 check: content read through the query layer, scoped to one
 | site and one locale and out of the trash, with what is internal kept from a
 | reader who has not overridden access.
 */
class ContentTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.locales', ['en', 'nl']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Post::class, SiteSettings::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    /* A row written by hand, so the reads are checked apart from the writes
       that will fill them. */
    private function insert(array $row = [], array $locales = ['en' => []], int $site = 1): int
    {
        $id = DB::table('post')->insertGetId(['site_id' => $site, 'featured' => false, ...$row]);

        foreach ($locales as $locale => $values) {
            DB::table('post_locales')->insert([
                'parent_id' => $id, 'site_id' => $site, 'locale' => $locale, 'title' => 'Hello', 'slug' => 'hello', 'status' => 'live', ...$values,
            ]);
        }

        return $id;
    }

    private function ids(iterable $entries): array
    {
        return collect($entries)->pluck('id')->all();
    }

    #[Test]
    public function it_reads_an_entry_in_the_locale_asked_for(): void
    {
        $id = $this->insert(
            ['published_at' => '2026-09-10 06:30:00', 'featured' => true, 'reading_minutes' => 4, 'created_at' => '2026-09-01 10:00:00'],
            ['en' => ['title' => 'Hello'], 'nl' => ['title' => 'Hallo', 'slug' => 'hallo']],
        );
        DB::table('uris')->insert(['site_id' => 1, 'locale' => 'nl', 'uri' => '/blog/hallo', 'type' => 'post', 'entry_id' => $id]);

        $post = Mainstay::findById(Post::class, $id, locale: 'nl');

        $this->assertInstanceOf(Post::class, $post);
        $this->assertSame($id, $post->id);
        $this->assertSame('nl', $post->locale);
        $this->assertSame('Hallo', $post->title);
        $this->assertSame('/blog/hallo', $post->uri);
        $this->assertTrue($post->featured);
        $this->assertSame(4, $post->readingMinutes, 'A readonly property is initialized from outside its class.');
        $this->assertSame('2026-09-10 06:30:00 UTC', $post->publishedAt->format('Y-m-d H:i:s T'));
        $this->assertSame('2026-09-01 10:00:00 UTC', $post->createdAt->format('Y-m-d H:i:s T'));
        $this->assertNull($post->updatedAt);

        $english = Mainstay::findById(Post::class, $id, locale: 'en');

        $this->assertSame('Hello', $english->title);
        $this->assertNull($english->uri, 'The path is the one held in the locale read.');
    }

    #[Test]
    public function an_entry_with_no_row_in_a_locale_is_not_read_in_it(): void
    {
        $id = $this->insert();

        $this->assertNull(Mainstay::findById(Post::class, $id, locale: 'nl'));
        $this->assertSame([], $this->ids(Mainstay::find(Post::class, locale: 'nl')));
    }

    #[Test]
    public function it_reads_in_the_request_locale_and_refuses_one_that_is_not_content(): void
    {
        $this->insert([], ['en' => [], 'nl' => ['title' => 'Hallo']]);

        App::setLocale('nl');
        $this->assertSame(['Hallo'], Mainstay::find(Post::class)->pluck('title')->all());

        App::setLocale('de');
        $this->assertThrows(
            fn () => Mainstay::find(Post::class),
            InvalidArgumentException::class,
            'No locale was passed and App::getLocale() is "de", which is not a content locale: mainstay.locales holds en, nl.',
        );
        $this->assertThrows(fn () => Mainstay::find(Post::class, locale: 'fr'), InvalidArgumentException::class, 'The locale is "fr"');
    }

    #[Test]
    public function a_trashed_entry_and_another_sites_entry_are_not_read(): void
    {
        $kept = $this->insert();
        $this->insert(['deleted_at' => '2026-09-01 10:00:00']);
        $campaign = DB::table('sites')->insertGetId(['handle' => 'campaign', 'name' => 'Campaign', 'hostname' => 'campaign.test']);
        $this->insert([], ['en' => []], $campaign);

        $this->assertSame([$kept], $this->ids(Mainstay::find(Post::class)));
    }

    #[Test]
    public function an_internal_field_is_absent_unless_access_is_overridden(): void
    {
        $id = $this->insert(['editor_note' => 'Check the quote']);

        $post = Mainstay::findById(Post::class, $id);

        $this->assertFalse((new ReflectionProperty($post, 'editorNote'))->isInitialized($post), 'Absent, not the default the declaration gave.');
        $this->assertSame('Check the quote', Mainstay::findById(Post::class, $id, overrideAccess: true)->editorNote);
    }

    #[Test]
    public function an_internal_field_is_refused_as_a_filter_or_sort_in_the_words_an_unknown_one_is(): void
    {
        $id = $this->insert(['editor_note' => 'Check the quote']);

        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['editorNote' => 'Check the quote']), InvalidArgumentException::class, 'has no field called editorNote to filter or sort on.');
        $this->assertThrows(fn () => Mainstay::find(Post::class, sort: '-editorNote'), InvalidArgumentException::class, 'has no field called editorNote to filter or sort on.');
        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['nothing' => 1]), InvalidArgumentException::class, 'has no field called nothing to filter or sort on.');

        $this->assertSame([$id], $this->ids(Mainstay::find(Post::class, where: ['editorNote' => 'Check the quote'], overrideAccess: true)));
    }

    #[Test]
    public function reads_ask_about_mainstays_user_and_not_the_default_guards(): void
    {
        $id = $this->insert(['editor_note' => 'Check the quote']);

        /* A host's rule that its own signed-in members may do anything. Asked
           about the default guard, it would hand this visitor the note. */
        Gate::before(fn ($user) => true);
        $this->actingAs(new GenericUser(['id' => 1]));

        $post = Mainstay::findById(Post::class, $id);

        $this->assertFalse((new ReflectionProperty($post, 'editorNote'))->isInitialized($post));
    }

    #[Test]
    public function where_compares_a_date_as_the_utc_column_holds_it(): void
    {
        $early = $this->insert(['published_at' => '2026-09-10 06:30:00']);
        $this->insert(['published_at' => '2026-09-10 07:30:00']);

        /* 07:00 UTC. Formatted in its own zone it would read 09:00 and match
           both rows. */
        $cut = CarbonImmutable::parse('2026-09-10 09:00:00', 'Europe/Amsterdam');

        $this->assertSame([$early], $this->ids(Mainstay::find(Post::class, where: ['publishedAt' => ['<=' => $cut]])));
    }

    #[Test]
    public function where_takes_equality_null_in_and_not_in_across_both_tables(): void
    {
        $draft = $this->insert(['featured' => true], ['en' => ['status' => 'draft', 'slug' => 'draft']]);
        $live = $this->insert(['published_at' => '2026-09-10 06:30:00'], ['en' => ['slug' => 'live']]);

        $this->assertSame([$draft], $this->ids(Mainstay::find(Post::class, where: ['featured' => true])));
        $this->assertSame([$draft], $this->ids(Mainstay::find(Post::class, where: ['publishedAt' => null])));
        $this->assertSame([$live], $this->ids(Mainstay::find(Post::class, where: ['publishedAt' => ['!=' => null]])));
        $this->assertSame([$draft], $this->ids(Mainstay::find(Post::class, where: ['status' => ['in' => ['draft']]])));
        $this->assertSame([$live], $this->ids(Mainstay::find(Post::class, where: ['status' => ['not_in' => ['draft']]])));
        $this->assertSame([$live], $this->ids(Mainstay::find(Post::class, where: ['id' => ['!=' => $draft], 'slug' => 'live'])));
        $this->assertSame([$draft, $live], $this->ids(Mainstay::find(Post::class, where: ['createdAt' => null])));
    }

    #[Test]
    public function where_refuses_a_bare_list_and_an_operator_it_does_not_take(): void
    {
        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['status' => ['draft', 'live']]), InvalidArgumentException::class, "write ['in' => [...]]");
        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['title' => ['like' => '%Hel%']]), InvalidArgumentException::class, 'like is not an operator a where takes');
        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['publishedAt' => ['<' => null]]), InvalidArgumentException::class, 'Only = and != take null.');
    }

    #[Test]
    public function every_sort_ends_on_the_id_and_names_it_once(): void
    {
        /* Asserted on the statement, because no driver here returns tied rows
           out of id order to be caught doing it -- which is what the
           tie-breaker guards against where one does. */
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $orderBy = function (callable $read) use (&$statements): string {
            $statements = [];
            $read();

            return strtolower(substr(end($statements), strripos(end($statements), ' order by ')));
        };
        $ids = fn (string $orderBy) => preg_match_all('/[`"\[]id[`"\]]/', $orderBy);

        $byDate = $orderBy(fn () => Mainstay::find(Post::class, sort: '-publishedAt'));
        $this->assertSame(1, $ids($byDate));
        $this->assertMatchesRegularExpression('/[`"\[]id[`"\]] asc$/', $byDate);

        $this->assertSame(1, $ids($orderBy(fn () => Mainstay::find(Post::class, sort: '-id'))), 'SQL Server refuses a column named twice.');
        $this->assertSame(1, $ids($orderBy(fn () => Mainstay::find(Post::class, sort: ['-publishedAt', 'id']))));
    }

    #[Test]
    public function sort_puts_nulls_last_either_way_and_pages_in_that_order(): void
    {
        $undated = $this->insert();
        $early = $this->insert(['published_at' => '2026-01-01 00:00:00']);
        $late = $this->insert(['published_at' => '2026-06-01 00:00:00']);
        $tie = $this->insert(['published_at' => '2026-06-01 00:00:00']);

        $this->assertSame([$late, $tie, $early, $undated], $this->ids(Mainstay::find(Post::class, sort: '-publishedAt')));
        $this->assertSame([$early, $late, $tie, $undated], $this->ids(Mainstay::find(Post::class, sort: ['publishedAt'])));
        $this->assertSame([$late, $tie], $this->ids(Mainstay::find(Post::class, sort: '-publishedAt', limit: 2)));

        $page = Mainstay::paginate(Post::class, sort: '-publishedAt', perPage: 2, page: 2);

        $this->assertSame(4, $page->total());
        $this->assertSame([$early, $undated], $this->ids($page->items()));
        $this->assertInstanceOf(Post::class, $page->items()[0]);
    }

    #[Test]
    public function it_reads_registered_entries_and_nothing_else(): void
    {
        $this->assertThrows(fn () => Mainstay::find(SiteSettings::class), InvalidArgumentException::class, 'is not an entry');
        $this->assertThrows(fn () => Mainstay::find(Article::class), InvalidArgumentException::class, 'is not a registered content type');
    }
}
