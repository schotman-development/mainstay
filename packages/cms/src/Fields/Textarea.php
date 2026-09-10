<?php

namespace Mainstay\Fields;

use Attribute;
use ReflectionProperty;

/* Long-form plain text. Rich text is phase 7 and is a different field. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Textarea extends Field
{
    /* Text's guard, and not Text's subclass: the two share a property type and
       nothing else, and inheriting here would inherit a `max` that a text
       column has no width to spend it on. */
    public function bind(ReflectionProperty $property): static
    {
        $this->stores($property, ['string'], 'a textarea field stores strings');

        return parent::bind($property);
    }

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
