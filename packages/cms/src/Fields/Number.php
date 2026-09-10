<?php

namespace Mainstay\Fields;

use Attribute;

/*
 | Integer or float is read off the property rather than declared twice. A
 | field on `int $stock` that stored a float column would be a declaration
 | disagreeing with the type it hydrates into.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Number extends Field
{
    public function __construct(
        public readonly int|float|null $min = null,
        public readonly int|float|null $max = null,
        bool $required = false,
        bool $localized = false,
        ?string $label = null,
    ) {
        parent::__construct($required, $localized, $label);
    }

    public function column(): ?array
    {
        return [$this->isFloat() ? 'float' : 'integer'];
    }

    public function rules(): array
    {
        return array_values(array_filter([
            ...parent::rules(),
            $this->isFloat() ? 'numeric' : 'integer',
            $this->min === null ? null : "min:{$this->min}",
            $this->max === null ? null : "max:{$this->max}",
        ]));
    }

    public function cast(mixed $value): mixed
    {
        return $value === null ? null : ($this->isFloat() ? (float) $value : (int) $value);
    }

    protected function json(): array
    {
        return array_filter([
            'type' => $this->isFloat() ? 'number' : 'integer',
            'minimum' => $this->min,
            'maximum' => $this->max,
        ], fn ($value) => $value !== null);
    }

    /* Anything that is not plainly `int` is treated as a float: a union, or a
       property with no type at all, loses the fraction if the guess goes the
       other way, and a float column holding whole numbers loses nothing. */
    private function isFloat(): bool
    {
        return $this->phpType !== 'int';
    }
}
