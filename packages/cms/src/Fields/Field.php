<?php

namespace Mainstay\Fields;

use Illuminate\Support\Str;
use ReflectionNamedType;
use ReflectionProperty;

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
 | Provisional until phase 8. Six scalars agree with each other too easily; the
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

    public function __construct(
        public readonly bool $required = false,
        public readonly bool $localized = false,
        public readonly ?string $label = null,
    ) {}

    public function bind(ReflectionProperty $property): static
    {
        $declared = $property->getType();

        $this->name = $property->getName();
        $this->phpType = $declared instanceof ReflectionNamedType ? $declared->getName() : 'mixed';
        $this->nullable = $declared === null || $declared->allowsNull();

        return $this;
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
     | TODO(you): this is the one real decision in phase 1.
     |
     | Two sources of truth for "must have a value" are already in the
     | declaration, and they do not have to agree:
     |
     |   #[Text(required: true)] public string $title      both say yes
     |   #[Text] public ?string $subtitle                  both say no
     |   #[Text] public string $status                     the type says yes,
     |                                                     the attribute is
     |                                                     silent
     |   #[Text(required: false)] public string $title     they contradict
     |
     | Deriving it from the property type is declaration-first and cannot drift,
     | since the class would not compile in disagreement with itself. But "not
     | null" is not "not empty": an empty string satisfies `string` and is
     | exactly what an editor submits when they leave a box alone. And a typed
     | property that has never been assigned is uninitialized, a third state
     | that is neither null nor a value.
     |
     | Taking the argument alone says precisely what the editor experiences, at
     | the cost of letting a declaration contradict its own type -- and the
     | contradiction surfaces as a TypeError at hydration, a long way from the
     | line that caused it.
     |
     | Whatever this returns drives two things: the leading validation rule in
     | rules() below, and the `required` array of the type's JSON Schema, which
     | is what a consumer generates its TypeScript from. Answering "required
     | when the argument says so, or when the type is not nullable" is the
     | obvious middle, and it makes the fourth line above unreachable -- which
     | may be right, or may be a contradiction worth throwing on instead.
     |
     | The stub takes the argument alone so the list and the schema are honest
     | about only what was declared. Replace it.
     */
    public function isRequired(): bool
    {
        return $this->required;
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
     | Subclasses convert real values in from() and to(); nothing blank() calls
     | empty ever reaches them.
     */
    public function cast(mixed $value): mixed
    {
        return $this->blank($value) ? null : $this->from($value);
    }

    public function serialize(mixed $value): mixed
    {
        return $this->blank($value) ? null : $this->to($value);
    }

    /*
     | An empty box posts an empty string, and on a field that can hold nothing
     | that is what it means: `?int $stock` left alone is absent, not zero.
     |
     | On a field that cannot -- `public string $title` -- it is the type's
     | empty value instead, for the same reason schema() reads nullability off
     | the property: null is not a string, and the property would refuse it at
     | hydration.
     |
     | Protected, because not every type has an empty value to fall back on. A
     | date has none, and a select's is not '' but nothing at all -- both widen
     | this rather than smuggle the exception into their conversion.
     */
    protected function blank(mixed $value): bool
    {
        return $value === null || ($value === '' && $this->nullable);
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
     | components themselves arrive with the form in phase 5.
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
