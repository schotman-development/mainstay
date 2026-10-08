<?php

namespace Mainstay;

use Doctrine\Inflector\InflectorFactory;
use Doctrine\Inflector\Language;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Mainstay\Auth\Gate;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\Media;
use Mainstay\Content\Taxonomy;
use Mainstay\Policies\MediaPolicy;

class Navigation
{
    /*
     | The admin's sections, as data for <x-mainstay::sidebar>, built from the
     | registered types rather than written by hand: each entry type and then
     | each taxonomy under Content, the media library, and each global under
     | Globals. A type is offered only to someone who may write to it -- its
     | list is no use to anyone else -- the library to someone who may read
     | it, and a section with nothing in it is left out.
     |
     | Links come from route(), so an admin mounted at another path or on a
     | subdirectory's app gets its own.
     */
    public static function sections(): array
    {
        $types = array_filter(app(Mainstay::class)->registered(), self::writes(...));
        $item = fn (string $type, string $icon) => ['href' => route('mainstay.entries', $type::handle(), absolute: false), 'label' => self::label($type), 'icon' => $icon];

        return array_values(array_filter([
            [
                /* No group name: the root of the panel is not a category of anything. */
                'items' => [['href' => route('mainstay.admin', absolute: false), 'label' => 'Dashboard', 'icon' => self::icon('<path d="M3.25 1.75h9.5a1.5 1.5 0 0 1 1.5 1.5v9.5a1.5 1.5 0 0 1-1.5 1.5h-9.5a1.5 1.5 0 0 1-1.5-1.5v-9.5a1.5 1.5 0 0 1 1.5-1.5zM5.25 10.75V7.5M8 10.75V5.25M10.75 10.75V8.75" />')]],
            ],
            [
                'label' => 'Content',
                'items' => [
                    ...array_map(fn (string $type) => $item($type, self::icon('<path d="M9.5 1.75H4.5a1 1 0 0 0-1 1v10.5a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V4.75z" /><path d="M9.5 1.75V4.75h3" />')), array_values(array_filter($types, fn (string $type) => ! is_subclass_of($type, GlobalSet::class) && ! is_subclass_of($type, Taxonomy::class)))),
                    ...array_map(fn (string $type) => $item($type, self::icon('<path d="M8.2 1.75H2.5a.75.75 0 0 0-.75.75v5.7c0 .2.08.39.22.53l5.55 5.55a.75.75 0 0 0 1.06 0l5.2-5.2a.75.75 0 0 0 0-1.06L8.73 1.97a.75.75 0 0 0-.53-.22Z" /><circle cx="5.1" cy="5.1" r=".9" />')), array_values(array_filter($types, fn (string $type) => is_subclass_of($type, Taxonomy::class)))),
                ],
            ],
            [
                'items' => self::library() ? [['href' => route('mainstay.media', absolute: false), 'label' => 'Media', 'icon' => self::icon('<rect x="1.75" y="2.75" width="12.5" height="10.5" rx="1.5" /><circle cx="5.5" cy="6.25" r="1.25" /><path d="m14.25 10.25-3.5-3.5-6.75 6.5" />')]] : [],
            ],
            [
                'label' => 'Globals',
                'items' => array_map(fn (string $type) => $item($type, self::icon('<path d="M1.75 4.75h12.5M1.75 11.25h12.5" /><circle cx="6" cy="4.75" r="1.75" /><circle cx="10" cy="11.25" r="1.75" />')), array_values(array_filter($types, fn (string $type) => is_subclass_of($type, GlobalSet::class)))),
            ],
        ], fn (array $section) => $section['items'] !== []));
    }

    /*
     | What the command centre offers: every link in the sidebar, and a new
     | one of each type that has a list.
     |
     | @return list<array{id: string, label: string, group: string, href: string}>
     */
    public static function commands(): array
    {
        $commands = [];

        foreach (self::sections() as $section) {
            foreach ($section['items'] as $item) {
                $commands[] = ['id' => $item['href'], 'label' => $item['label'], 'group' => 'Go to', 'href' => $item['href']];
            }
        }

        foreach (array_filter(app(Mainstay::class)->registered(), self::writes(...)) as $handle => $type) {
            if (! is_subclass_of($type, GlobalSet::class)) {
                $commands[] = ['id' => "new-{$handle}", 'label' => 'New '.Str::lower(self::label($type, plural: false)), 'group' => 'Create', 'href' => route('mainstay.entries.create', $handle, absolute: false)];
            }
        }

        return $commands;
    }

    /*
     | A type's name on screen: its class headlined, an entry type's and a
     | taxonomy's in the plural -- Articles, Tags -- by the English inflector,
     | as its capabilities are, and a global's as it is, there being one.
     */
    public static function label(string $type, bool $plural = true): string
    {
        static $english;
        $english ??= InflectorFactory::createForLanguage(Language::ENGLISH)->build();

        $name = class_basename($type);

        return Str::headline($plural && ! is_subclass_of($type, GlobalSet::class) ? $english->pluralize($name) : $name);
    }

    /* Whether the signed-in user may write to a type at all: make one of
       its entries or terms, or change its global. */
    public static function writes(string $type): bool
    {
        return Gate::about($type)->allows(is_subclass_of($type, GlobalSet::class) ? 'update' : 'create', [$type, $type]);
    }

    /* Whether the signed-in user may see the media library: the question
       its every read asks, so the sidebar and its screens -- and the API
       to come -- answer alike. */
    public static function library(): bool
    {
        return self::media('viewAny');
    }

    /* Whether the signed-in user may upload to the media library. */
    public static function uploads(): bool
    {
        return self::media('create');
    }

    /* Whether the library would let the signed-in user do this, to this
       image or to the library: what decides whether a screen offers it. */
    public static function media(string $ability, Media|string $subject = Media::class): bool
    {
        return Gate::for(Media::class, MediaPolicy::class)->allows($ability, $subject);
    }

    /* Through the same component every other icon goes through, rather than a
       hand-written svg beside it that can drift from the house weight. */
    private static function icon(string $shapes): string
    {
        return Blade::render("<x-mainstay::icon>{$shapes}</x-mainstay::icon>");
    }
}
