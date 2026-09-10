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

    /* Every driver has its own idea of what it hands back for a boolean
       column -- 0, "0", b"\0" -- and none of them is `false`. */
    protected function from(mixed $value): mixed
    {
        return (bool) $value;
    }

    /* A bool rather than an int, because Postgres rejects an integer written
       to a boolean column outright rather than coercing it, and MySQL and
       SQLite take the bool just as happily. */
    protected function to(mixed $value): mixed
    {
        return (bool) $value;
    }

    protected function json(): array
    {
        return ['type' => 'boolean'];
    }
}
