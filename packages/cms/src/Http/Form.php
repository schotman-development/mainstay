<?php

namespace Mainstay\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mainstay\Auth\Capabilities;
use Mainstay\Content\Entry;
use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Field;
use Mainstay\Fields\Relation;
use Mainstay\Fields\Terms;
use Mainstay\Fields\Text;
use Mainstay\Mainstay;
use Mainstay\Navigation;
use Throwable;

/*
 | What an admin form posts, turned into what a write takes.
 |
 | A draft saved with a field equal to what is live drops that field from the
 | draft, so a form posting every field it drew would undo another editor's
 | change to a field this one never touched: their title is in the draft, this
 | form still holds the old one, and saving it puts the old one back. So each
 | field is drawn with a fingerprint of the value it was drawn with, and a
 | field posted unchanged from it is left out of the save. Two editors
 | changing different fields both keep their change, as drafts' merging saves
 | intend, and one field changed by both takes the later.
 */
final class Form
{
    /*
     | The fields a form draws for the signed-in user: every one, but an
     | internal field only for whoever may see it, which the layer would
     | otherwise refuse as a field it does not have.
     |
     | @return array<string, Field>
     */
    public static function fields(string $type, bool $internal): array
    {
        return array_filter(app(Mainstay::class)->fields($type), fn (Field $field) => $internal || ! $field->internal);
    }

    /*
     | A value as its field stores it, hashed, in the form a browser posts it
     | back: a date with an offset is the moment it was drawn as, and text is
     | compared as the host's middleware leaves it -- trimmed, an empty one
     | null -- with its lines ending as they would in a textarea's. So code
     | that wrote spaces around a title does not read as an edit, and a tree
     | is compared with its keys in one order, since a column and an editor
     | each write them in their own. Null where a posted value is not one the
     | field takes: it is always sent, so the write says why.
     */
    public static function fingerprint(Field $field, mixed $value, bool $posted = false): ?string
    {
        try {
            if ($value !== null && $posted && Validator::make([$field->name => $value], $field->rulesAt($field->name, $value, draft: true))->fails()) {
                return null;
            }

            $stored = $value === null ? null : $field->serialize($value);

            if (is_string($stored)) {
                $stored = trim(str_replace("\r\n", "\n", $stored));
            }

            return sha1(json_encode($stored === '' ? null : self::sorted($stored), JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
    }

    /* The locale a screen is in: the one asked for, the default otherwise. */
    public static function locale(Request $request): string
    {
        $locales = array_keys(app(Mainstay::class)->locales());

        return in_array($request->query('locale'), $locales, true) ? $request->query('locale') : $locales[0];
    }

    /* The field a type's slug is: the Text field its route ends in, in the
       first locale's pattern. Null for a type with none. */
    public static function slug(string $class): ?string
    {
        $mainstay = app(Mainstay::class);
        $route = $mainstay->route($class);
        $pattern = is_array($route) ? reset($route) : $route;

        if ($pattern === null || ! preg_match('/\{(\w+)\}\z/', $pattern, $last) || $last[1] === 'title') {
            return null;
        }

        return ($mainstay->fields($class)[$last[1]] ?? null) instanceof Text ? $last[1] : null;
    }

    /*
     | What a read the form makes to draw a field gives, or null where the
     | reader may not read it -- a host's policy closing a type or the
     | library -- so the field draws what it holds as missing, as the
     | entry's own read does, rather than the form failing.
     */
    public static function readable(callable $read): mixed
    {
        try {
            return $read();
        } catch (AuthorizationException) {
            return null;
        }
    }

    /*
     | A relation's chosen entries as its rows draw them: what each posts,
     | its title, and its type's name where the field points at several. One
     | the read did not load keeps its id and has no title. After a refused
     | save the posted references are read again.
     |
     | @return list<array{value: string, title: ?string, type: ?string}>
     */
    public static function rows(Relation $field, mixed $value, string $locale): array
    {
        $mainstay = app(Mainstay::class);
        $rows = [];

        foreach ($field->many() ? (is_array($value) ? $value : []) : [$value] as $item) {
            if ($item instanceof Entry) {
                [$class, $id, $entry] = [$item::class, $item->id, $item->missing ? null : $item];
            } elseif (is_string($item) && $item !== '') {
                [$handle, $id] = $field->several() ? explode(':', $item, 2) + [1 => ''] : [$field->to[0]::handle(), $item];
                $class = $mainstay->registered()[$handle] ?? null;

                if ($class === null || ! ctype_digit($id)) {
                    continue;
                }

                $entry = self::readable(fn () => $mainstay->findById($class, (int) $id, locale: $locale, depth: 0));
            } else {
                continue;
            }

            $rows[] = [
                'value' => $field->several() ? $class::handle().':'.$id : (string) $id,
                'title' => $entry?->title,
                'type' => $field->several() ? Navigation::label($class, plural: false) : null,
            ];
        }

        return $rows;
    }

    /*
     | A terms field's chips -- each its title and what it posts, `id:5` --
     | and every term of the taxonomy in the locale, by title, for the input
     | to suggest and match typed text against.
     |
     | @return array{0: list<array{0: string, 1: string}>, 1: array<string, string>}
     */
    public static function chips(Terms $field, mixed $value, string $locale): array
    {
        $terms = [];

        foreach (self::readable(fn () => app(Mainstay::class)->find($field->of, locale: $locale, depth: 0)) ?? [] as $term) {
            $terms["id:{$term->id}"] = $term->title;
        }

        $chips = [];

        foreach (is_array($value) ? $value : [] as $item) {
            $chips[] = match (true) {
                $item instanceof Entry => [$item->missing ? "Missing ({$item->id})" : $item->title, "id:{$item->id}"],
                is_string($item) && $item !== '' => [$terms[$item] ?? (str_starts_with($item, 'new:') ? substr($item, 4) : 'Missing ('.substr($item, 3).')'), $item],
                default => null,
            };
        }

        return [array_values(array_filter($chips)), $terms];
    }

    /*
     | Each tag typed rather than picked, as a term: one already holding the
     | slug its title makes, in any locale, or a new one of that title written
     | in every locale alike, so a tag created in Dutch exists in English
     | under the same name until someone translates it. The ids are made
     | unique after, so a tag both picked and typed is one term. Called
     | inside the save's transaction, so a save refused creates none.
     | Creating needs `manage`, without which the field is refused naming it,
     | and what the taxonomy refuses is refused under the field too.
     */
    public static function terms(string $class, array $data): array
    {
        $mainstay = app(Mainstay::class);
        $locales = array_keys($mainstay->locales());

        foreach ($mainstay->fields($class) as $name => $field) {
            if (! $field instanceof Terms || ! is_array($data[$name] ?? null)) {
                continue;
            }

            $slug = self::slug($field->of);
            $made = [];

            $data[$name] = array_values(array_unique(array_map(function (mixed $chip) use ($mainstay, $locales, $field, $name, $slug, &$made) {
                if (! is_array($chip) || array_keys($chip) !== ['new'] || ! is_string($chip['new'])) {
                    return $chip;
                }

                $title = trim($chip['new']);
                $values = ['title' => $title];

                if ($title === '') {
                    throw ValidationException::withMessages([$name => 'A tag needs a title.']);
                }

                if (isset($made[$key = mb_strtolower($title)])) {
                    return $made[$key];
                }

                if ($slug !== null) {
                    $values[$slug] = Str::slug($title);

                    if ($values[$slug] === '') {
                        throw ValidationException::withMessages([$name => "The tag \"{$title}\" makes no slug. Give it a letter or a digit."]);
                    }

                    foreach ($locales as $locale) {
                        if (($held = self::readable(fn () => $mainstay->find($field->of, where: [$slug => $values[$slug]], locale: $locale, depth: 0)->first())) !== null) {
                            return $made[$key] = $held->id;
                        }
                    }
                }

                try {
                    $term = $mainstay->create($field->of, $values, locale: $locales[0]);

                    foreach (array_slice($locales, 1) as $locale) {
                        $mainstay->update($field->of, $term->id, $values, locale: $locale);
                    }
                } catch (AuthorizationException) {
                    throw ValidationException::withMessages([$name => 'Adding a new tag needs '.Capabilities::names($field->of, 'manage')[0].'.']);
                } catch (ValidationException $exception) {
                    /* The taxonomy's own refusal, said under the field the
                       tag was typed in rather than the entry's fields of the
                       same names. */
                    throw ValidationException::withMessages([$name => 'The tag "'.Str::limit($title, 40).'" was refused: '.implode(' ', Arr::flatten($exception->errors()))]);
                }

                return $made[$key] = $term->id;
            }, $data[$name]), SORT_REGULAR));
        }

        return $data;
    }

    /* Every map in a tree with its keys sorted; a list keeps its order. */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /*
     | What the request changed, by field, in what a write takes: each field
     | posted, through its fromForm(), unless it matches the fingerprint it
     | was drawn with. The template likewise.
     |
     | @param  array<string, Field>  $fields
     */
    public static function changes(Request $request, string $type, array $fields): array
    {
        $seen = (array) $request->input('_seen', []);
        $data = [];

        foreach ($fields as $name => $field) {
            if (! $request->has($name)) {
                continue;
            }

            $value = $field->fromForm($request->input($name));

            if (isset($seen[$name]) && ($print = self::fingerprint($field, $value, posted: true)) !== null && hash_equals((string) $seen[$name], $print)) {
                continue;
            }

            $data[$name] = $value;
        }

        if (! is_subclass_of($type, GlobalSet::class) && $request->has('template') && ($seen['template'] ?? null) !== sha1((string) $request->input('template'))) {
            $data['template'] = $request->input('template');
        }

        return $data;
    }
}
