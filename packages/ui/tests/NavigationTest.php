<?php

namespace Mainstay\Ui\Tests;

use Mainstay\Ui\Navigation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class NavigationTest extends TestCase
{
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

    /* The key is a position in the tree, so a test reads better asking which
       label it landed on. */
    private function marked(array $sections, ?string $current): ?string
    {
        $key = Navigation::activeKey($sections, $current);

        if ($key === null) {
            return null;
        }

        $parts = explode('.', $key);
        $item = $sections[array_shift($parts)];

        foreach ($parts as $index) {
            $item = $item['items'][$index];
        }

        return $item['label'];
    }

    #[Test]
    public function it_marks_the_item_the_pathname_belongs_to(): void
    {
        $this->assertSame('Pages', $this->marked($this->sections, '/admin/pages'));
    }

    #[Test]
    public function a_child_route_still_marks_its_section(): void
    {
        $this->assertSame('Pages', $this->marked($this->sections, '/admin/pages/42/edit'));
    }

    /* The reason the match is longest-first: /admin is a prefix of every route. */
    #[Test]
    public function the_shallowest_item_does_not_claim_its_siblings(): void
    {
        $this->assertSame('Settings', $this->marked($this->sections, '/admin/settings'));
        $this->assertSame('Overview', $this->marked($this->sections, '/admin'));
    }

    #[Test]
    public function a_partial_segment_is_not_a_match(): void
    {
        $this->assertSame('Overview', $this->marked($this->sections, '/admin/pages-archive'));
        $this->assertNull($this->marked($this->sections, '/elsewhere'));
        $this->assertNull($this->marked($this->sections, null));
    }

    /*
     | A caller handing over the full URL rather than the path used to fall
     | through to the shallowest item, marking the wrong section outright.
     */
    #[Test]
    public function a_query_string_or_hash_is_not_part_of_the_route(): void
    {
        $this->assertSame('Pages', $this->marked($this->sections, '/admin/pages?status=draft'));
        $this->assertSame('Pages', $this->marked($this->sections, '/admin/pages#top'));
        $this->assertSame('Pages', $this->marked($this->sections, '/admin/pages/42?tab=seo#meta'));
    }

    /* A doubled slash used to fall through and mark the shallowest item instead. */
    #[Test]
    public function repeated_slashes_are_not_part_of_the_route(): void
    {
        $this->assertSame('Pages', $this->marked($this->sections, '/admin//pages'));
        $this->assertSame('Pages', $this->marked($this->sections, '//admin//pages//42'));
        $this->assertSame('Pages', $this->marked([['items' => [['href' => '/admin//pages', 'label' => 'Pages']]]], '/admin/pages'));
    }

    #[Test]
    public function trailing_slashes_match_on_either_side(): void
    {
        $this->assertSame('Pages', $this->marked($this->sections, '/admin/pages/'));
        $this->assertSame('Pages', $this->marked([['items' => [['href' => '/admin/pages/', 'label' => 'Pages']]]], '/admin/pages'));
    }

    /* "/" prefixes every path on the site, so it can only ever match itself. */
    #[Test]
    public function a_root_item_does_not_claim_the_whole_site(): void
    {
        $root = [['items' => [['href' => '/', 'label' => 'Home']]]];

        $this->assertSame('Home', $this->marked($root, '/'));
        $this->assertNull($this->marked($root, '/totally/unrelated'));
        $this->assertNull($this->marked([['items' => [['href' => '', 'label' => 'Home']]]], '/anything'));
    }

    /* Matching that stopped at the first level handed this to Collections. */
    #[Test]
    public function a_sub_item_route_beats_the_parent_it_hangs_off(): void
    {
        $this->assertSame('New post', $this->marked($this->tree, '/admin/collections/posts/new'));
        $this->assertSame('Posts', $this->marked($this->tree, '/admin/collections/posts/42/edit'));
        $this->assertSame('Collections', $this->marked($this->tree, '/admin/collections'));
    }

    /*
     | Two items on the same route are two different positions, so only one of
     | them is the active one -- a pinned shortcut beside its section marks one
     | link, not both. The position is what makes that true: items compared by
     | value would both match.
     */
    #[Test]
    public function two_items_on_the_same_route_resolve_to_one_position(): void
    {
        $pinned = array_merge(
            [['label' => 'Pinned', 'items' => [['href' => '/admin/pages', 'label' => 'Pages']]]],
            $this->sections,
        );

        $this->assertSame('0.0', Navigation::activeKey($pinned, '/admin/pages'));
    }
}
