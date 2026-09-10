<?php

namespace Mainstay\Fields;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Text extends Field
{
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
