<?php

namespace Mainstay\Fields;

use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

/*
 | The field type contract, and the attribute a declaration is written with, in
 | one class. `#[Text(required: true)]` constructs one of these, so the object
 | reflection hands back is already the thing that knows its column, its rules,
 | its JSON Schema fragment, its casts and the component that draws it. A
 | separate field-type class behind a name-to-class registry would be a second
 | layer with nothing in between: nothing ever looks a field type up by name,
 | because the instance is on the property.
 |
 | A host adds a field type by writing a subclass. There is no registration
 | call, which is one extension point fewer rather than one more.
 |
 | Provisional until phase 10. Six scalars agree with each other too easily; the
 | types that will actually shape this are the ones storing in JSON, needing a
 | sibling row, or drawing an interface with state in it.
 */
abstract class Field
{
    /*
     | Bound from the property the attribute sits on. Reflection is already
     | reading the name and the type to build the list, so a declaration never
     | restates either -- `#[Text] public ?string $subtitle` says "optional
     | string called subtitle" once, in PHP's own vocabulary.
     |
     | Readonly because the registry memoizes the list and hands the same
     | objects to every caller. A field one screen quietly renamed would be
     | renamed for the schema, the validator and the column plan as well.
     */
    public readonly string $name;

    public readonly string $phpType;

    public readonly bool $nullable;

    /*
     | The view namespace component() draws from. A property rather than a
     | literal because a host cannot add views to Mainstay's own: a field type
     | is only registration-free if the half of it that draws lives somewhere
     | the host can put it.
     */
    protected string $viewNamespace = 'mainstay';

    /*
     | These three lead every field type's constructor, and a type's own
     | arguments come after them, so the first positional argument means
     | `required` whatever the attribute is. Written the other way round --
     | which is the way it reads, since `#[Text(120)]` is the interesting
     | argument first -- `#[Text(true)]` is a varchar(1) and `#[Boolean(true)]`
     | is a required flag, and neither says which one it is.
     */
    public function __construct(
        public readonly ?bool $required = null,
        public readonly bool $localized = false,
        public readonly ?string $label = null,
    ) {}

    public function bind(ReflectionProperty $property): static
    {
        $declared = $property->getType();

        $this->name = $property->getName();
        $this->phpType = $declared instanceof ReflectionNamedType ? $declared->getName() : 'mixed';
        $this->nullable = $declared === null || $declared->allowsNull();

        /*
         | The one declaration isRequired() cannot answer for: `required: false`
         | on a property that has no null to be optional with. Refused here,
         | where both halves are first in the same place, rather than left to
         | surface as a TypeError at hydration.
         */
        if ($this->contradicts()) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is declared optional but its type cannot hold null.',
                $property->getDeclaringClass()->getName(),
                $this->name,
            ));
        }

        return $this;
    }

    /*
     | Refuse a property this field cannot hydrate into. Every field type whose
     | from() hands back something narrower than the value it was given needs
     | this, and needs it in bind(): a mistyped declaration otherwise reads as
     | if it works and then either raises a TypeError two frames into hydration
     | with nothing in it naming the attribute, or -- where the cast coerces --
     | reports nothing at all and stores the wrong thing.
     |
     | Every name in the declaration has to be one of $names, which is stricter
     | than PHP for a union: PHP stores a value that matches any single arm, so
     | the loose reading accepts `string|int` and lets the coercion pick the
     | arm. See Date::bind() for why the loose reading cannot be written
     | correctly at all.
     |
     | `mixed` and an untyped property hold anything. `null` is allowed as an
     | arm and not as a type: `?int` and `int|null` both reflect as a plain
     | `int` that allows null, so the name only ever turns up beside others --
     | `int|float|null`, `(A&B)|null` -- or as `public null $x`, which is a
     | property nothing this field casts to can be stored in.
     */
    protected function stores(ReflectionProperty $property, array $names, string $because): void
    {
        $declared = $this->names($property->getType());

        foreach ($declared as $name) {
            if (in_array($name, [...$names, 'mixed'], true) || ($name === 'null' && count($declared) > 1)) {
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                '%s::$%s is typed %s, and %s.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                (string) $property->getType(),
                $because,
            ));
        }
    }

    /* Flattened rather than matched arm by arm, so a union, an intersection
       and the union-of-intersections PHP allows all answer the same way, and
       none of them is a shape this reaches the end of without a name.

       @return list<string> */
    protected function names(?ReflectionType $type): array
    {
        return match (true) {
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType,
            $type instanceof ReflectionIntersectionType => array_merge(
                ...array_map($this->names(...), $type->getTypes()),
            ),
            /* No type at all, which holds anything. */
            default => [],
        };
    }

    /*
     | The column this field wants, as a Blueprint method and its arguments, or
     | null for a field that lives in the type's JSON column and therefore costs
     | no migration at all.
     */
    abstract public function column(): ?array;

    /* The JSON Schema fragment, before nullability is applied to it. */
    abstract protected function json(): array;

    /*
     | Required when the argument says so, or when the property has no null to
     | be optional with. A silent attribute defers to the type, which is why the
     | argument is nullable: `null` is "unstated", and `false` on a non-nullable
     | property is a contradiction -- see contradicts(), which a type that
     | redefines `required` redefines with it.
     */
    public function isRequired(): bool
    {
        return $this->required ?? ! $this->nullable;
    }

    /*
     | Whether the declaration disagrees with itself. Its own question rather
     | than an expression inline in bind(), because a type that redefines what
     | `required` asks -- Boolean does -- redefines this with it.
     */
    protected function contradicts(): bool
    {
        return $this->required === false && ! $this->nullable;
    }

    /*
     | Laravel validation rules. Subclasses prepend their own to these, so the
     | presence rule always leads and a field type never restates it.
     */
    public function rules(): array
    {
        return [$this->isRequired() ? 'required' : 'nullable'];
    }

    public function schema(): array
    {
        $json = $this->json();

        /*
         | Nullability comes off the property rather than off isRequired(): an
         | optional field that is not nullable holds its type's empty value, and
         | telling a consumer it might be null would be a lie its compiler then
         | makes them handle.
         */
        if ($this->nullable && isset($json['type'])) {
            $json['type'] = [$json['type'], 'null'];

            /* An enum is a closed list, so widening the type without widening
               the list leaves a schema that admits null and then rejects it. */
            if (isset($json['enum'])) {
                $json['enum'][] = null;
            }
        }

        return $json;
    }

    /*
     | Database to PHP, and back, with the empty box decided once here rather
     | than by each field type's own idea of nothing -- 0, false, *today*.
     |
     | from() sees only real values. to() sees one more: the empty value a
     | non-nullable field falls back to, since that is what gets written.
     */
    public function cast(mixed $value): mixed
    {
        if (! $this->blank($value)) {
            return $this->from($value);
        }

        return $this->nullable ? null : $this->empty();
    }

    public function serialize(mixed $value): mixed
    {
        if (! $this->blank($value)) {
            return $this->to($value);
        }

        return $this->nullable ? null : $this->to($this->empty());
    }

    /*
     | Nothing at all: a null column, an empty box, a box holding only spaces.
     | Whether that becomes null is cast()'s question, not this one -- a type
     | that trimmed here and a type that did not was the old split, and it made
     | `'  '` an absent date but a zero stock.
     */
    protected function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /*
     | What a field that cannot hold null holds instead: a value of its own that
     | means nothing. Text, Textarea, Number and Boolean each have one -- '',
     | '', 0 and false are values those fields are willing to store. A date has
     | none, and a select's options are a closed list that does not contain
     | "none of them", so either would have to invent one.
     |
     | Not "what the property type can hold": keptText and keptChoice are both
     | `public string` and only one of them has an answer. Not "what the field's
     | own rules accept" either -- keptText stores the '' its own `required`
     | rejects.
     |
     | The read path reaches here on an ordinary NULL column, so throwing is not
     | reserved for a bypassed validator -- `#[Number] public int` gets its 0
     | through a validator that passed, and a non-nullable date declared over a
     | nullable column gets this instead of a TypeError two frames later, which
     | is the same news delivered where the declaration is named.
     */
    protected function empty(): mixed
    {
        throw new InvalidArgumentException(sprintf(
            '%s is empty, and %s has no empty value. Declare the property nullable.',
            $this->name,
            class_basename(static::class),
        ));
    }

    /*
     | What mainstay:sync writes into rows that already exist when this field's
     | column becomes required -- added to a table holding content, or
     | tightened from nullable. Serialized, since it goes straight to the
     | column.
     |
     | Not empty(): that is what every read of a null column falls back to,
     | and a date or a select inventing a value there would invent it on every
     | read. This is one write, in development, where a value that is merely
     | valid beats a column that cannot be added. A type with no empty value
     | overrides this, or sync falls back to the zero of its column's type.
     */
    public function backfill(): mixed
    {
        return $this->serialize(null);
    }

    /* Identity for anything the driver already hands back in the shape the
       property is typed for. */
    protected function from(mixed $value): mixed
    {
        return $value;
    }

    protected function to(mixed $value): mixed
    {
        return $value;
    }

    /*
     | The Blade component that draws this field in the admin, derived from the
     | class name so a host's field type gets one by writing the file. The
     | components themselves arrive with the form in phase 10.
     */
    public function component(): string
    {
        return $this->viewNamespace.'::fields.'.Str::kebab(class_basename(static::class));
    }

    public function label(): string
    {
        return $this->label ?? Str::headline($this->name);
    }
}
