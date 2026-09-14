<?php

namespace Mainstay\Fields;

use Attribute;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Text extends Field
{
    /* A varchar hydrates into a string. Without this `#[Text] public int
       $count` plans the column, writes the rules and then raises a TypeError
       at hydration, two frames from anything naming the attribute. */
    public function bind(ReflectionProperty $property): static
    {
        $this->stores($property, ['string'], 'a text field stores strings');

        return parent::bind($property);
    }

    /*
     | The length is the column's as well as the rule's. A varchar has to be
     | some width, and taking it from the field is the only version where the
     | limit an editor is held to and the limit the database enforces cannot
     | disagree.
     */
    public function __construct(
        ?bool $required = null,
        bool $localized = false,
        ?string $label = null,
        public readonly int $max = 255,
    ) {
        parent::__construct($required, $localized, $label);
    }

    protected function empty(): mixed
    {
        return '';
    }

    public function column(): ?array
    {
        return ['string', $this->max];
    }

    public function rules(): array
    {
        return [...parent::rules(), 'string', "max:{$this->max}"];
    }

    protected function json(): array
    {
        return ['type' => 'string', 'maxLength' => $this->max];
    }
}
