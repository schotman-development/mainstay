<?php

namespace Mainstay\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Field;
use Mainstay\Mainstay;
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
     | that wrote spaces around a title does not read as an edit. Null where a
     | posted value is not one the field takes: it is always sent, so the
     | write says why.
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

            return sha1(json_encode($stored === '' ? null : $stored, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
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
