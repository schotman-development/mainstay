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
     | TODO(you): still the one real decision on this screen.
     |
     | Everything else here is layout. This function is the screen's behaviour:
     | given what the server holds and what the form is holding, it decides what
     | the primary button says, whether it is the accent button or the quiet
     | one, whether it is pressable at all, and what the line beside it tells
     | you.
     |
     | It has to cover at least these, and they do not all want the same answer:
     |
     |   - a draft, untouched            nothing to do, but the screen should
     |                                   still offer the way forward (publish?)
     |   - a draft, edited               save it -- but does saving publish it?
     |   - published, untouched          resting state; the button has no work
     |   - published, edited             editing something readers can already
     |                                   see is the case worth being loudest
     |                                   about
     |   - blank (no title)              cannot be saved yet, and should say why
     |
     | The trade-offs worth weighing:
     |
     |   One button or two? WordPress splits "Save draft" from "Publish" and
     |   makes you learn which is which; Notion has neither and saves as you
     |   type. One button whose label changes is the middle road, at the cost of
     |   a target that means something different depending on when you look.
     |
     |   Should an untouched screen's button be disabled or just quiet? A
     |   disabled control is honest about having nothing to do, but it also
     |   cannot be focused, so a keyboard user tabbing the header finds a hole.
     |
     |   How loud is "unsaved"? An edit to a published entry is a live document
     |   drifting from what readers see. Reaching for the danger variant there
     |   is defensible -- so is deciding red belongs to destruction alone.
     |
     |   And one this screen adds: content and metadata now save separately,
     |   since the body is edited on the site. Does this button speak for the
     |   whole entry or only for the form? Saying "Saved" while the inline
     |   editor still holds unsaved blocks would be a lie the user cannot catch.
     |
     |   New in Blade: the form goes dirty in the browser, not on the server.
     |   This runs once per render, so whatever it decides has to be something
     |   the bundle can carry the rest of the way -- today that is the hint
     |   alone, in dirty-form.ts.
     |
     | changed() above answers "is it dirty". This answers what to do about it.
     | The stub below gives every case the same answer so the story still
     | renders; replace it.
     */
    public static function saveState(array $saved, array $current): array
    {
        return [
            'label' => 'Save',
            'variant' => 'primary',
            'disabled' => false,
            'hint' => self::changed($saved, $current) ? 'Unsaved changes' : '',
        ];
    }
}
