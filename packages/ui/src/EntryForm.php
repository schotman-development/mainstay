<?php

namespace Mainstay\Ui;

class EntryForm
{
    /* Google truncates around here. Not a limit -- going over costs you the tail
       of a snippet, not the entry -- so the counter warns and never blocks. */
    public const SEO_DESCRIPTION_LIMIT = 160;

    /*
     | True when the form differs from what is on the server.
     |
     | Read off the entries rather than from a list of field names, so a field
     | added to an entry is covered the day it is added. A hand-kept list is a
     | list that drifts, and the failure is silent in the worst possible way: an
     | edit that never registers as unsaved is an edit you lose without being
     | warned.
     |
     | Both sides' keys, not just the saved one: optional fields exist, and one
     | being set for the first time is a key the saved entry does not have yet.
     | Reading only its keys misses the very edit that added it.
     |
     | modified is the one exclusion, and it is the server's rather than the
     | form's: it changes *as a result of* saving, so counting it would leave
     | every entry reading as dirty the instant it was saved.
     */
    public static function changed(array $saved, array $current): bool
    {
        foreach (array_keys($saved + $current) as $field) {
            if ($field === 'modified') {
                continue;
            }

            /* Lists compare by contents and in order: tags are rebuilt on every
               edit, so identity would call every entry dirty forever, and an
               unordered compare would miss a reordering that is a real edit.
               PHP's === on arrays is exactly that, which is the one place this
               is shorter than the JavaScript it came from. */
            if (($saved[$field] ?? null) !== ($current[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /*
     | The buttons a form offers, from what the server knows: whether a draft is
     | waiting, and whether the user may publish. Two buttons, as WordPress has
     | them -- Save draft keeps the work where readers cannot see it, Publish
     | saves and puts it live -- and someone who may not publish gets the first
     | alone. A waiting draft is said out loud and can be thrown away.
     |
     | Whether the form holds edits the server has not seen is the browser's to
     | know, so dirty-form.ts marks the page `data-dirty` and the emphasis moves
     | in CSS: Save draft leads while there are unsaved edits, Publish once
     | there are none. Neither is ever disabled, which would leave a hole in
     | the bar for a keyboard to fall through.
     */
    public static function saveState(bool $drafted, bool $publishes): array
    {
        return [
            'publish' => $publishes,
            'discard' => $drafted,
            'hint' => $drafted ? 'Unpublished changes' : '',
        ];
    }
}
