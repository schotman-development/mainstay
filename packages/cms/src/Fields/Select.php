<?php

namespace Mainstay\Fields;

use Attribute;
use BackedEnum;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionNamedType;
use ReflectionProperty;

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
        public readonly array|string $options = [],
        ?bool $required = null,
        bool $localized = false,
        ?string $label = null,
    ) {
        if ($options === []) {
            throw new InvalidArgumentException('A select field needs options: nothing satisfies an empty one.');
        }

        if (is_string($options) && ! is_subclass_of($options, BackedEnum::class)) {
            throw new InvalidArgumentException("{$options} is not a backed enum, so it has no options to offer.");
        }

        parent::__construct($required, $localized, $label);
    }

    /*
     | `value => label`, or a list where each option is its own label.
     |
     | ponytail: `[0 => 'Off', 1 => 'On']` and `['Off', 'On']` are the same
     | array once PHP has built it, so the list reading wins and the keys are
     | gone. A backed enum is the way to say "stored value, and a label that is
     | not it" when the values are numeric.
     */
    public function options(): array
    {
        if (is_string($this->options)) {
            return array_reduce(
                ($this->options)::cases(),
                fn (array $options, BackedEnum $case) => $options + [$case->value => Str::headline($case->name)],
                [],
            );
        }

        return array_is_list($this->options)
            ? array_combine($this->options, $this->options)
            : $this->options;
    }

    public function values(): array
    {
        return array_map(strval(...), array_keys($this->options()));
    }

    /*
     | A select stores strings: the column is a varchar, the schema says string
     | and the enum is a list of strings, so a value read back is one too. The
     | class comment has claimed that since the type was written; here it is
     | enforced, because `#[Select(options: Priority::class)] public ?Priority`
     | is the declaration a backed enum invites and cast() hands it a string.
     */
    public function bind(ReflectionProperty $property): static
    {
        /* Before parent::bind(), so a property that is both wrongly typed and
           wrongly optional hears about the type first -- it is the half that
           explains the other.

           The declared type rather than $phpType, which is 'mixed' for an
           untyped property and for every union alike, so `int|float` would pass
           a check on that and then take a string. */
        $declared = $property->getType();

        if ($declared !== null && ! ($declared instanceof ReflectionNamedType && in_array($declared->getName(), ['string', 'mixed'], true))) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is typed %s, and a select stores strings. A backed enum names the options, it does not type the property.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                (string) $declared,
            ));
        }

        return parent::bind($property);
    }

    protected function from(mixed $value): mixed
    {
        return (string) $value;
    }

    protected function to(mixed $value): mixed
    {
        return (string) $value;
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
