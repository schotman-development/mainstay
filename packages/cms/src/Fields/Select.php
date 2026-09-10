<?php

namespace Mainstay\Fields;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Select extends Field
{
    /*
     | Value to label, or a plain list when the value is already what an editor
     | should read. The stored values are the keys either way, so the enum a
     | consumer generates and the `in:` rule a save is checked against are the
     | same list.
     */
    public function __construct(
        public readonly array $options = [],
        bool $required = false,
        bool $localized = false,
        ?string $label = null,
    ) {
        if ($options === []) {
            throw new InvalidArgumentException('A select field needs options: nothing satisfies an empty one.');
        }

        parent::__construct($required, $localized, $label);
    }

    public function options(): array
    {
        return array_is_list($this->options)
            ? array_combine($this->options, $this->options)
            : $this->options;
    }

    public function values(): array
    {
        return array_map(strval(...), array_keys($this->options()));
    }

    /* A select stores strings: the column is a varchar, the schema says string
       and the enum is a list of strings, so a value read back is one too. A
       property typed for anything else is a declaration disagreeing with the
       field it carries. */
    public function cast(mixed $value): mixed
    {
        return $value === null ? null : (string) $value;
    }

    public function column(): ?array
    {
        return ['string', 255];
    }

    /*
     | No `string` rule beside the `in:` -- the list is already the whole of
     | what is allowed. Values are quoted the way Laravel's own Rule::in quotes
     | them, because an unquoted option containing a comma reads as two options
     | and accepts halves of itself.
     */
    public function rules(): array
    {
        $quoted = array_map(fn (string $value) => '"'.str_replace('"', '""', $value).'"', $this->values());

        return [...parent::rules(), 'in:'.implode(',', $quoted)];
    }

    protected function json(): array
    {
        return ['type' => 'string', 'enum' => $this->values()];
    }
}
