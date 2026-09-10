<?php

namespace Mainstay\Fields;

use Attribute;
use Carbon\CarbonImmutable;
use DateTimeInterface;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Date extends Field
{
    /*
     | `time` is here rather than left to a later phase because publish state is
     | a timestamp, not a day, and it is the same field with one flag rather
     | than a second field type.
     */
    public function __construct(
        public readonly bool $time = false,
        bool $required = false,
        bool $localized = false,
        ?string $label = null,
    ) {
        parent::__construct($required, $localized, $label);
    }

    public function column(): ?array
    {
        return [$this->time ? 'dateTime' : 'date'];
    }

    public function rules(): array
    {
        return [...parent::rules(), 'date'];
    }

    /*
     | A date has no empty value the way a string has "" and an integer has 0,
     | so an empty box is absent whether the property is nullable or not.
     |
     | Whitespace as well as "", because CarbonImmutable::parse(" ") is *now*
     | and would silently date an entry today. TrimStrings would have caught
     | that on the way in from a form, but this is the field API and nothing
     | promises a request ran first.
     */
    protected function blank(mixed $value): bool
    {
        return parent::blank($value) || (is_string($value) && trim($value) === '');
    }

    protected function from(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value);
    }

    /* Through from() rather than beside it, so a date written out is parsed
       the same way one read in was. */
    protected function to(mixed $value): mixed
    {
        return $this->from($value)->format($this->time ? 'Y-m-d H:i:s' : 'Y-m-d');
    }

    protected function json(): array
    {
        return ['type' => 'string', 'format' => $this->time ? 'date-time' : 'date'];
    }
}
