<?php

namespace Mainstay\Ui\Demo;

/*
 | The workshop's demo content: shared by the list story and the test that pins
 | the shaping, so neither can drift from the other. Dev-only -- it is autoloaded
 | from the root composer's autoload-dev and never ships with the package.
 */
class Entries
{
    /* Stands in for a real rendered page preview. */
    public const PREVIEW = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 28'%3E%3Crect width='40' height='28' fill='%23e7e5e0'/%3E%3Crect x='5' y='6' width='30' height='3' rx='1.5' fill='%238b8880'/%3E%3Crect x='5' y='13' width='21' height='2.5' rx='1.25' fill='%23bdbab3'/%3E%3Crect x='5' y='19' width='26' height='2.5' rx='1.25' fill='%23bdbab3'/%3E%3C/svg%3E";

    public static function pages(): array
    {
        return [
            ['title' => 'Careers', 'path' => '/careers', 'status' => 'Published', 'category' => 'Company', 'author' => 'Katherine Johnson', 'released' => '2026-02-18', 'modified' => '2026-08-29', 'thumbnail' => self::PREVIEW],
            ['title' => 'Home', 'path' => '/', 'status' => 'Published', 'category' => 'Marketing', 'author' => 'Ada Lovelace', 'released' => '2025-11-04', 'modified' => '2026-09-06', 'thumbnail' => self::PREVIEW],
            ['title' => 'Terms of service', 'path' => '/terms', 'status' => 'Draft', 'category' => 'Legal', 'author' => 'Grace Hopper', 'released' => null, 'modified' => '2026-08-29'],
            ['title' => 'About', 'path' => '/about', 'status' => 'Published', 'category' => 'Marketing', 'author' => 'Grace Hopper', 'released' => '2025-11-04', 'modified' => '2026-09-05'],
            ['title' => 'Pricing', 'path' => '/pricing', 'status' => 'Draft', 'category' => 'Marketing', 'author' => 'Ada Lovelace', 'released' => null, 'modified' => '2026-09-03'],
        ];
    }

    public static function posts(): array
    {
        return [
            ['title' => 'Shipping the new editor', 'path' => '/blog/shipping-the-new-editor', 'status' => 'Published', 'category' => 'Product', 'author' => 'Ada Lovelace', 'released' => '2026-09-06', 'modified' => '2026-09-06', 'thumbnail' => self::PREVIEW],
            ['title' => 'What headless actually buys you', 'path' => '/blog/what-headless-actually-buys-you', 'status' => 'Published', 'category' => 'Engineering', 'author' => 'Katherine Johnson', 'released' => '2026-09-01', 'modified' => '2026-09-02'],
            ['title' => 'A field guide to content modelling for teams who have outgrown their spreadsheet', 'path' => '/blog/content-modelling-field-guide', 'status' => 'Draft', 'category' => 'Engineering', 'author' => 'Grace Hopper', 'released' => null, 'modified' => '2026-09-01'],
            ['title' => 'Release notes: 0.4', 'path' => '/blog/release-notes-0-4', 'status' => 'Draft', 'category' => 'Changelog', 'author' => 'Ada Lovelace', 'released' => null, 'modified' => '2026-08-11'],
        ];
    }

    /* Enough rows to page. */
    public static function many(): array
    {
        return array_merge(self::pages(), self::posts(), array_map(
            fn (array $page) => [...$page, 'title' => $page['title'].' (copy)', 'path' => $page['path'].'-copy', 'status' => 'Draft'],
            self::pages(),
        ));
    }

    /* A rendered preview for the entry screen, wider than the list's. */
    public const DETAIL_PREVIEW = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 25'%3E%3Crect width='40' height='25' fill='%23e7e5e0'/%3E%3Crect x='4' y='4' width='22' height='3' rx='1.5' fill='%238b8880'/%3E%3Crect x='4' y='10' width='32' height='2' rx='1' fill='%23bdbab3'/%3E%3Crect x='4' y='14' width='28' height='2' rx='1' fill='%23bdbab3'/%3E%3Crect x='4' y='18' width='30' height='2' rx='1' fill='%23bdbab3'/%3E%3C/svg%3E";

    /* Shared with the entry screen's stories and its test: a populated screen
       rather than a blank one. */
    public static function draft(): array
    {
        return [
            'title' => 'Shipping the new editor',
            'path' => '/blog/shipping-the-new-editor',
            'status' => 'Draft',
            'category' => 'Product',
            'author' => 'Ada Lovelace',
            'released' => null,
            'modified' => '2026-09-06',
            'thumbnail' => self::DETAIL_PREVIEW,
            'excerpt' => 'What changed, why it took three attempts, and what we threw away in between.',
            'tags' => ['editor', 'prosemirror', 'release'],
            'seoTitle' => '',
            'seoDescription' => '',
            'blocks' => 14,
        ];
    }

    /* Live, and filled in the way something that has been through review is. */
    public static function published(): array
    {
        return [...self::draft(),
            'title' => 'What headless actually buys you',
            'path' => '/blog/what-headless-actually-buys-you',
            'status' => 'Published',
            'category' => 'Engineering',
            'released' => '2026-09-01',
            'modified' => '2026-09-02',
            'tags' => ['architecture', 'api'],
            'seoTitle' => 'What headless CMS architecture actually buys you',
            'seoDescription' => 'The case for separating the editing experience from the delivery layer, and the three costs nobody mentions when they make it.',
            'blocks' => 22,
        ];
    }

    /* What "New post" opens. Nothing filled in, no content yet, and no release
       date -- an entry has none until it has been released. */
    public static function blank(): array
    {
        return [...self::draft(),
            'title' => '',
            'path' => '',
            'status' => 'Draft',
            'category' => 'Product',
            'released' => null,
            'modified' => '2026-09-07',
            'thumbnail' => null,
            'excerpt' => '',
            'tags' => [],
            'seoTitle' => '',
            'seoDescription' => '',
            'blocks' => 0,
        ];
    }
}
