<?php

namespace Mainstay\Fields;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Boolean extends Field
{
    public function column(): ?array
    {
        return ['boolean'];
    }

    public function rules(): array
    {
        return [...parent::rules(), 'boolean'];
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
