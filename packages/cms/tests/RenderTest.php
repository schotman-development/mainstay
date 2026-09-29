<?php

namespace Mainstay\Tests;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Facades\Mainstay;
use Mainstay\Tests\Fixtures\Home;
use Mainstay\Tests\Fixtures\Leaf;
use Mainstay\Tests\Fixtures\Lost;
use Mainstay\Tests\Fixtures\Page;
use Mainstay\Tests\Fixtures\Post;
use Mainstay\Tests\Fixtures\Shadowed;
use Orchestra\Testbench\Attributes\DefineRoute;
use PHPUnit\Framework\Attributes\Test;

/*
 | The phase 4 check: an entry written through the query layer, visited at
 | its path, in its locale by prefix or by host, and drawn by the view its
 | entry, its type or its handle names.
 */
class RenderTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
        $app['view']->addLocation(__DIR__.'/Fixtures/views');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Post::class, Page::class, Home::class, Leaf::class, Lost::class, Shadowed::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function writePost(array $data = [], string $locale = 'en'): Post
    {
        return Mainstay::create(Post::class, ['title' => 'Hello', 'slug' => 'hello', 'status' => 'live', ...$data], locale: $locale, overrideAccess: true);
    }

    private function translate(int $id): void
    {
        Mainstay::update(Post::class, $id, ['title' => 'Hallo', 'slug' => 'hallo', 'status' => 'live'], locale: 'nl', overrideAccess: true);
    }

    /* A host's own routes, one of them on a path an entry holds, and a
       fallback of its own. */
    protected function handWritten($router): void
    {
        $router->get('blog/hello', fn () => 'hand-written');
        $router->fallback(fn () => 'host fallback');
    }

    #[Test]
    public function a_path_renders_its_entry_with_the_view_named_after_its_type(): void
    {
        $this->writePost();

        $this->get('/blog/hello')
            ->assertOk()
            ->assertSee('<h1>Hello</h1>', escape: false)
            ->assertSee('<a href="/blog/hello">', escape: false);
    }

    #[Test]
    public function an_entry_renders_with_its_own_view_before_its_types_and_its_handles(): void
    {
        $id = $this->writePost(['template' => 'special'])->id;
        $home = Mainstay::create(Home::class, ['title' => 'Welcome'], locale: 'en', overrideAccess: true);

        $this->get('/blog/hello')->assertOk()->assertSee('special: Hello');
        $this->get('/')->assertOk()->assertSee('home: Welcome');

        Mainstay::update(Home::class, $home->id, ['template' => 'special'], locale: 'en', overrideAccess: true);
        $this->get('/')->assertOk()->assertSee('special: Welcome');

        $post = Mainstay::update(Post::class, $id, ['template' => ''], locale: 'en', overrideAccess: true);

        $this->assertNull($post->template, 'Blank takes the view off.');
        $this->assertSame('post', Mainstay::template($post));
        $this->get('/blog/hello')->assertSee('<h1>Hello</h1>', escape: false);
    }

    #[Test]
    public function a_view_named_and_missing_is_an_error_rather_than_a_step_down(): void
    {
        Mainstay::create(Lost::class, [], locale: 'en', overrideAccess: true);
        $id = $this->writePost()->id;
        /* A view deleted after the entry named it. */
        DB::table('post')->where('id', $id)->update(['template' => 'deleted']);

        $this->withoutExceptionHandling();
        $this->assertThrows(fn () => $this->get('/lost'), InvalidArgumentException::class, 'View [nowhere] not found.');
        $this->assertThrows(fn () => $this->get('/blog/hello'), InvalidArgumentException::class, 'View [deleted] not found.');

        $this->assertSame('Still', Mainstay::update(Post::class, $id, ['title' => 'Still'], locale: 'en', overrideAccess: true)->title, 'A stored view is not checked again.');
    }

    #[Test]
    public function a_view_written_to_an_entry_is_one_its_type_lists(): void
    {
        foreach ([
            ['leaf', 'The template field is one of the views this type renders with: post, special.'],
            ['mainstay::admin', 'The template field is one of the views this type renders with: post, special.'],
            [['post'], 'The template field must be a string.'],
        ] as [$view, $message]) {
            $this->assertThrows(fn () => $this->writePost(['template' => $view]), ValidationException::class, $message);
        }

        $this->assertThrows(
            fn () => Mainstay::create(Leaf::class, ['title' => 'X', 'slug' => 'x', 'template' => 'special'], locale: 'en', overrideAccess: true),
            ValidationException::class,
            'The template field is one of the views this type renders with: leaf.',
        );

        $this->assertSame(0, DB::table('post')->count() + DB::table('leaf')->count());
        $this->assertSame(['pages.home', 'special'], Mainstay::templates(Home::class));
    }

    #[Test]
    public function a_prefixed_locale_is_served_under_its_prefix(): void
    {
        $this->translate($this->writePost()->id);
        Mainstay::create(Home::class, ['title' => 'Welkom'], locale: 'nl', overrideAccess: true);

        $this->get('/nl/nieuws/hallo')->assertOk()->assertSee('<h1>Hallo</h1>', escape: false)->assertSee('locale nl');
        $this->get('/nl')->assertOk()->assertSee('home: Welkom');
        $this->get('/nl/')->assertOk()->assertSee('home: Welkom');
        $this->get('/blog/hello')->assertOk()->assertSee('locale en');

        $this->get('/nieuws/hallo')->assertNotFound();
        $this->get('/nl/blog/hello')->assertNotFound();
        $this->get('/')->assertNotFound();
    }

    #[Test]
    public function a_prefix_matches_whole_segments(): void
    {
        Mainstay::create(Leaf::class, ['title' => 'X', 'slug' => 'x'], locale: 'nl', overrideAccess: true);
        Mainstay::create(Leaf::class, ['title' => 'Nlx', 'slug' => 'nlx'], locale: 'en', overrideAccess: true);

        $this->get('/nl/x')->assertOk()->assertSee('leaf: X');
        $this->get('/nlx')->assertOk()->assertSee('leaf: Nlx');
    }

    #[Test]
    public function with_every_locale_prefixed_the_root_is_nobodys(): void
    {
        config()->set('mainstay.locales', ['en' => '/en', 'nl' => '/nl']);
        Mainstay::create(Home::class, ['title' => 'Welcome'], locale: 'en', overrideAccess: true);

        $this->get('/')->assertNotFound();
        $this->get('/en')->assertOk()->assertSee('home: Welcome');
    }

    #[Test]
    public function a_locale_with_a_host_is_served_there_whatever_the_scheme_and_port(): void
    {
        config()->set('mainstay.locales', ['en' => 'https://example.test', 'nl' => 'http://nl.example.test:8000']);
        $id = $this->writePost()->id;
        $this->translate($id);

        $this->get('http://example.test/blog/hello')->assertOk()->assertSee('locale en');
        $this->get('https://nl.example.test/nieuws/hallo')->assertOk()->assertSee('<h1>Hallo</h1>', escape: false);
        $this->get('http://NL.example.test/nieuws/hallo')->assertOk();

        $this->get('http://nl.example.test/blog/hello')->assertNotFound();
        $this->get('http://elsewhere.test/blog/hello')->assertNotFound();

        $this->assertSame('https://example.test/blog/hello', Mainstay::findById(Post::class, $id, locale: 'en')->url());
        $this->assertSame('http://nl.example.test:8000/nieuws/hallo', Mainstay::findById(Post::class, $id, locale: 'nl')->url());
    }

    #[Test]
    public function a_link_is_the_locales_base_and_the_path(): void
    {
        $id = $this->writePost()->id;
        $this->translate($id);
        $home = Mainstay::create(Home::class, ['title' => 'Welcome'], locale: 'en', overrideAccess: true);
        Mainstay::update(Home::class, $home->id, ['title' => 'Welkom'], locale: 'nl', overrideAccess: true);

        $this->assertSame('/blog/hello', Mainstay::findById(Post::class, $id, locale: 'en')->url());
        $this->assertSame('/nl/nieuws/hallo', Mainstay::findById(Post::class, $id, locale: 'nl')->url());
        $this->assertSame('/', Mainstay::findById(Home::class, $home->id, locale: 'en')->url());
        $this->assertSame('/nl', Mainstay::findById(Home::class, $home->id, locale: 'nl')->url(), 'Not /nl/.');
    }

    #[Test]
    public function a_path_that_leads_to_no_entry_is_not_found(): void
    {
        $id = $this->writePost()->id;
        DB::table('uris')->insert(['site_id' => 1, 'locale' => 'en', 'uri' => '/ghost', 'type' => 'ghost', 'entry_id' => $id]);

        /* Not asked at all: MySQL and SQL Server would match it to
           /blog/hello by folding case, and SQLite and Postgres would not. */
        $this->get('/Blog/Hello')->assertNotFound();
        $this->assertNull(Mainstay::findByUri('/Blog/hello', locale: 'en'));

        $this->get('/blog/nope')->assertNotFound();
        $this->get('/ghost')->assertNotFound();

        Mainstay::delete(Post::class, $id, overrideAccess: true);
        $this->get('/blog/hello')->assertNotFound();
    }

    #[Test]
    public function a_type_the_visitor_may_not_read_is_not_found_rather_than_refused(): void
    {
        Mainstay::create(Page::class, ['section' => 'about', 'slug' => 'team'], locale: 'en', overrideAccess: true);

        $this->get('/about/team')->assertNotFound();
        $this->assertNull(Mainstay::findByUri('/about/team', locale: 'en'));
        $this->assertInstanceOf(Page::class, Mainstay::findByUri('/about/team', locale: 'en', overrideAccess: true));
    }

    #[Test]
    public function an_internal_field_is_not_on_the_page(): void
    {
        $this->writePost(['editorNote' => 'Secret']);

        $this->get('/blog/hello')->assertOk()->assertSee('no note')->assertDontSee('Secret');
    }

    #[Test]
    #[DefineRoute('handWritten')]
    public function a_route_the_host_wrote_wins_and_its_fallback_does_not_replace_the_catch_all(): void
    {
        $this->writePost();
        $this->writePost(['title' => 'Other', 'slug' => 'other']);

        $this->get('/blog/hello')->assertOk()->assertSee('hand-written');
        $this->get('/blog/other')->assertOk()->assertSee('<h1>Other</h1>', escape: false);
        $this->get('/nowhere')->assertNotFound();
    }

    #[Test]
    public function the_locale_is_set_before_the_lookup_so_a_404_is_in_it(): void
    {
        $this->get('/nl/nowhere')->assertNotFound();

        $this->assertSame('nl', App::getLocale());
    }

    #[Test]
    public function a_path_another_locale_or_the_admin_answers_is_refused(): void
    {
        foreach ([
            'nl' => "The path /nl is answered by nl's prefix /nl in en.",
            'admin' => 'The path /admin is answered by the admin at /admin in en.',
        ] as $slug => $message) {
            $this->assertThrows(fn () => Mainstay::create(Leaf::class, ['title' => 'X', 'slug' => $slug], locale: 'en', overrideAccess: true), ValidationException::class, $message);
        }

        $this->assertSame(0, DB::table('leaf')->count());

        /* Inside its own prefix nothing else answers, and the admin on a host
           of its own leaves the path free. */
        Mainstay::create(Leaf::class, ['title' => 'X', 'slug' => 'nl'], locale: 'nl', overrideAccess: true);
        config()->set('mainstay.domain', 'admin.test');
        Mainstay::create(Leaf::class, ['title' => 'Y', 'slug' => 'admin'], locale: 'en', overrideAccess: true);

        $this->assertThrows(
            fn () => Mainstay::create(Shadowed::class, ['slug' => 'x'], locale: 'en', overrideAccess: true),
            InvalidArgumentException::class,
            '#[Route] pattern "/nl/{slug}" puts every en path under nl\'s prefix /nl, which answers it instead.',
        );
    }

    #[Test]
    public function the_locale_map_is_refused_where_a_request_could_not_be_told_apart(): void
    {
        foreach ([
            [['en', 'nl'], 'mainstay.locales maps each content locale to where it is served'],
            [['en' => 'nl'], 'mainstay.locales serves en at "nl", which is neither'],
            [['en' => '/NL'], 'mainstay.locales serves en at "/NL"'],
            [['en' => '/', 'nl' => 'https://example.nl'], 'gives some locales a host and not others'],
            [['en' => 'https://example.test', 'nl' => 'http://EXAMPLE.test:8000/'], 'serves en and nl at the same base'],
        ] as [$locales, $message]) {
            config()->set('mainstay.locales', $locales);

            $this->assertThrows(fn () => Mainstay::locales(), InvalidArgumentException::class, $message);
        }

        $this->assertThrows(fn () => Mainstay::find(Post::class, locale: 'en'), InvalidArgumentException::class, 'serves en and nl at the same base');
    }
}
