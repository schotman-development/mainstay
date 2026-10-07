<?php

namespace Mainstay\Auth;

use Doctrine\Inflector\InflectorFactory;
use Doctrine\Inflector\Language;
use Illuminate\Auth\Access\Response;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\Taxonomy;

/*
 | What a user may be allowed to do, WordPress's way: strings, never stored,
 | computed from the registered types. Each type has a set of its own, named
 | from its handle, and each capability has a form that covers every type of
 | its shape, those deployed later included -- so a role names a few types or
 | all of them.
 */
final class Capabilities
{
    public const ENTRY = ['edit', 'edit_published', 'edit_others', 'publish', 'delete', 'delete_others'];

    public const GLOBAL = ['edit', 'publish'];

    public const TERM = ['manage'];

    /* The library is one, so its capabilities have no per-type form. */
    public const MEDIA = ['upload_media', 'edit_others_media'];

    public const USERS = 'manage_users';

    /*
     | A verb's two names for a type: its own and every type's.
     |
     | @return array{0: string, 1: string}
     */
    public static function names(string $type, string $verb): array
    {
        [$own, $every] = self::nouns($type);

        return ["{$verb}_{$own}", "{$verb}_{$every}"];
    }

    /** @return list<string> every name a type's own set holds */
    public static function of(string $type): array
    {
        return array_map(fn (string $verb) => self::names($type, $verb)[0], self::verbs($type));
    }

    /** @return list<string> every name no type owns: the all-types forms, the library's and the accounts' */
    public static function shared(): array
    {
        return [
            ...array_map(fn (string $verb) => "{$verb}_entries", self::ENTRY),
            ...array_map(fn (string $verb) => "{$verb}_globals", self::GLOBAL),
            ...array_map(fn (string $verb) => "{$verb}_terms", self::TERM),
            ...self::MEDIA,
            self::USERS,
        ];
    }

    /*
     | A policy's answer: allowed where the user holds one name of every list
     | given, refused naming the first they lack -- or `$nobody` where there
     | is no user, which names the override.
     */
    public static function check(?User $user, string $nobody, array ...$needs): Response
    {
        if ($user === null) {
            return Response::deny($nobody);
        }

        foreach ($needs as $names) {
            if (! $user->holds(...$names)) {
                return Response::deny("This needs {$names[0]}.");
            }
        }

        return Response::allow();
    }

    /** @return list<string> */
    private static function verbs(string $type): array
    {
        return match (true) {
            is_subclass_of($type, GlobalSet::class) => self::GLOBAL,
            is_subclass_of($type, Taxonomy::class) => self::TERM,
            default => self::ENTRY,
        };
    }

    /*
     | A global is one per site, so its own noun is its handle. The plural is
     | English whatever language the host gives Laravel's Pluralizer, which
     | would otherwise rename every capability a role holds.
     |
     | @return array{0: string, 1: string}
     */
    private static function nouns(string $type): array
    {
        static $english;
        $english ??= InflectorFactory::createForLanguage(Language::ENGLISH)->build();

        return match (true) {
            is_subclass_of($type, GlobalSet::class) => [$type::handle(), 'globals'],
            is_subclass_of($type, Taxonomy::class) => [$english->pluralize($type::handle()), 'terms'],
            default => [$english->pluralize($type::handle()), 'entries'],
        };
    }
}
