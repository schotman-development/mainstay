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

    public function cast(mixed $value): mixed
    {
        /* An empty box posts an empty string, and CarbonImmutable::parse('')
           is *now* -- which would silently date an entry today. */
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return CarbonImmutable::parse($value);
    }

    public function serialize(mixed $value): mixed
    {
        /* Through cast() rather than beside it, so the empty box an editor
           leaves alone is one absent date on the way out as well as in. */
        return $this->cast($value)?->format($this->time ? 'Y-m-d H:i:s' : 'Y-m-d');
    }

    protected function json(): array
    {
        return ['type' => 'string', 'format' => $this->time ? 'date-time' : 'date'];
    }
}
