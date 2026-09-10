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
    public function cast(mixed $value): mixed
    {
        return $value === null ? null : (bool) $value;
    }

    public function serialize(mixed $value): mixed
    {
        return $value === null ? null : (int) (bool) $value;
    }

    protected function json(): array
    {
        return ['type' => 'boolean'];
    }
}
