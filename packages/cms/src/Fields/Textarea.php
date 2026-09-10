<?php

namespace Mainstay\Fields;

use Attribute;

/* Long-form plain text. Rich text is phase 7 and is a different field. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Textarea extends Field
{
    protected function empty(): mixed
    {
        return '';
    }

    public function column(): ?array
    {
        return ['text'];
    }

    public function rules(): array
    {
        return [...parent::rules(), 'string'];
    }

    protected function json(): array
    {
        return ['type' => 'string'];
    }
}
