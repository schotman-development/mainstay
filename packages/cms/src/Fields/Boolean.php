<?php

namespace Mainstay\Fields;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Boolean extends Field
{
    protected function empty(): mixed
    {
        return false;
    }

    public function column(): ?array
    {
        return ['boolean'];
    }

    /*
     | Not parent::rules(): `required` fails on an absent key, which is exactly
     | what an unchecked box submits, so a plain boolean field would be one an
     | editor can never save off. A checkbox is never unanswered anyway -- the
     | only presence question a boolean has is "must it be true", which is
     | `accepted`, and only the argument can ask it -- `public bool` does not
     | mean "must be true", so isRequired() ignores the property type here.
     |
     | `boolean` refuses the "on" a bare checkbox posts, so the form in phase 5
     | writes `value="1"` and a hidden `0`, the way a Laravel form always has.
     */
    public function isRequired(): bool
    {
        return $this->required === true;
    }

    public function rules(): array
    {
        return $this->isRequired() ? ['accepted'] : ['nullable', 'boolean'];
    }

    /* Nothing to contradict: `required: false` on a boolean says "may be left
       off", and `public bool` holds that perfectly well as false. */
    protected function contradicts(): bool
    {
        return false;
    }

    /*
     | Every driver has its own idea of what it hands back for a boolean column
     | -- 0, "0", "f", b"\0" -- and none of them is `false`. A plain cast reads
     | the truthy ones as true, so a Postgres connection handing back strings
     | reports every stored false as true.
     |
     | A list of the falses rather than a list of the trues: an encoding nobody
     | here anticipated should read as true and be visible, not read as false
     | and look like data.
     */
    protected function from(mixed $value): mixed
    {
        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['', '0', 'f', 'false', 'off', 'no'], true);
        }

        return (bool) $value;
    }

    /* Through from(), because a form posts the same strings a driver hands
       back. A bool rather than an int, because Postgres rejects an integer
       written to a boolean column outright rather than coercing it, and MySQL
       and SQLite take the bool just as happily. */
    protected function to(mixed $value): mixed
    {
        return $this->from($value);
    }

    /* The base trims with PHP's default charlist, which includes "\0" -- the
       byte a MySQL bit column hands back for false. Blank means an empty box
       here; a stored false is a value, and from() is what reads it. */
    protected function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value, " \t\n\r\x0B") === '');
    }

    protected function json(): array
    {
        return ['type' => 'boolean'];
    }
}
