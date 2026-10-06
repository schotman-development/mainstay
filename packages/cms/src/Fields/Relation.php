<?php

namespace Mainstay\Fields;

use Attribute;
use Closure;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Mainstay\Content\Entry;
use ReflectionClass;
use ReflectionProperty;

/*
 | Entries this one points at, stored as plain ids with no foreign key -- in a
 | column of its own, or inside a block's JSON the same way:
 |
 |     #[Relation(to: Person::class)] public ?Person $author;
 |     #[Relation(to: Article::class)] public array $related;
 |     #[Relation(to: [Page::class, Blog::class])] public array $menu;
 |
 | One type in `to:` stores the id: one in an unsignedBigInteger column a
 | where can filter on, a list as a JSON list of ids. Several store each as
 | `{type, id}` by the type's handle, in JSON whether one or a list, since an
 | id alone does not say which table: each type numbers its own rows. The
 | property says one or a list -- nullable and typed as the target, or a
 | union of the targets, for one; `array` for a list, which holds none rather
 | than null.
 |
 | Resolved at the read's depth, defensively: an entry the read did not load
 | is one holding its id, marked missing -- see Entry::$missing -- and writing
 | it back writes the reference again. A reference to a type the field no
 | longer names is left out of a read and of the next save, as a block whose
 | type its field no longer lists is.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Relation extends Field
{
    /** @var list<class-string<Entry>> */
    public readonly array $to;

    /** @var array<string, class-string<Entry>> by handle */
    private array $handles = [];

    private bool $many = false;

    /** @param class-string<Entry>|list<class-string<Entry>> $to */
    public function __construct(
        ?bool $required = null,
        bool $localized = false,
        ?string $label = null,
        string|array $to = [],
    ) {
        $to = (array) $to;

        if ($to === [] || ! array_is_list($to)) {
            throw new InvalidArgumentException('A relation names the entries it points at: #[Relation(to: Article::class)], or a list of types.');
        }

        foreach ($to as $class) {
            if (! is_string($class) || ! is_subclass_of($class, Entry::class) || (new ReflectionClass($class))->isAbstract()) {
                throw new InvalidArgumentException(sprintf('%s is not an entry, and a relation points at entries.', is_string($class) ? $class : get_debug_type($class)));
            }

            $class = (new ReflectionClass($class))->getName();

            /* One handle is one stored `type`, which would read back as
               whichever class came last. */
            if (($existing = $this->handles[$class::handle()] ?? $class) !== $class) {
                throw new InvalidArgumentException("Two of a relation's types are called \"{$class::handle()}\": {$existing} and {$class}.");
            }

            $this->handles[$class::handle()] = $class;
        }

        $this->to = array_values($this->handles);

        parent::__construct($required, $localized, $label);
    }

    public function bind(ReflectionProperty $property): static
    {
        $this->many = in_array('array', $this->names($property->getType()), true);

        if ($this->many) {
            $this->stores($property, ['array'], 'a relation holds one entry, typed as what it points at, or a list of them as an array');
        } else {
            $because = 'a relation to one entry is typed as every type it points at: '.implode('|', $this->to);
            $this->stores($property, $this->to, $because);

            /* Every target, too, or a reference to the one left out is
               written and then cannot be read back into the property. */
            $declared = $this->names($property->getType());

            if ($declared !== [] && ! in_array('mixed', $declared, true) && array_diff($this->to, $declared) !== []) {
                throw new InvalidArgumentException(sprintf('%s::$%s is typed %s, and %s.', $property->getDeclaringClass()->getName(), $property->getName(), (string) $property->getType(), $because));
            }

            if (! ($property->getType()?->allowsNull() ?? true)) {
                throw new InvalidArgumentException(sprintf(
                    '%s::$%s is not nullable, and a relation holds null for no entry chosen. Declare it nullable; required: true still asks every write for one.',
                    $property->getDeclaringClass()->getName(),
                    $property->getName(),
                ));
            }
        }

        return parent::bind($property);
    }

    /* A list is required only when the argument says so: an empty one is a
       list holding none, which `required` refuses. */
    public function isRequired(): bool
    {
        return $this->many ? $this->required === true : parent::isRequired();
    }

    protected function contradicts(): bool
    {
        return ! $this->many && parent::contradicts();
    }

    protected function empty(): mixed
    {
        return $this->many ? [] : parent::empty();
    }

    public function column(): array
    {
        return $this->many || $this->several() ? ['json'] : ['unsignedBigInteger'];
    }

    public function rules(): array
    {
        return [...parent::rules(), ...match (true) {
            $this->many => ['array', 'list'],
            $this->several() => ['array:type,id'],
            default => ['bail', 'integer', 'min:1', self::ID],
        }];
    }

    /*
     | Each item of a list where it is, `related.2`, and a reference to one of
     | several types by its type and id. A repeat is refused at the second,
     | and an entry of a type the field does not name by what it is, rather
     | than as an id or a map it was never written as.
     */
    public function rulesAt(string $attribute, mixed $value = null): array
    {
        if (! $this->many) {
            return [...parent::rulesAt($attribute, $value), ...$this->item($attribute, $value, false)];
        }

        $rules = parent::rulesAt($attribute, $value);
        $seen = [];

        foreach (is_array($value) && array_is_list($value) ? $value : [] as $index => $item) {
            $rules = [...$rules, ...$this->item("{$attribute}.{$index}", $item, true)];

            if (($reference = $this->reference($item)) === null) {
                continue;
            }

            if (isset($seen[$key = implode(':', $reference)])) {
                $rules["{$attribute}.{$index}"][] = fn (string $attribute, mixed $value, Closure $fail) => $fail('The :attribute field names an entry already in the list.');
            }

            $seen[$key] = true;
        }

        return $rules;
    }

    /* The rules one reference answers to at `$at`: the list's own items, and
       the parts of one of several types. */
    private function item(string $at, mixed $item, bool $listed): array
    {
        if ($item instanceof Entry) {
            return [$at => [fn (string $attribute, mixed $value, Closure $fail) => $fail('The :attribute field points at '.implode(' or ', array_keys($this->handles)).', not '.$value::handle().'.')]];
        }

        $rules = $listed ? [$at => $this->several() ? ['required', 'array:type,id'] : ['bail', 'required', 'integer', 'min:1', self::ID]] : [];

        return $this->several() && is_array($item) ? [
            ...$rules,
            "{$at}.type" => ['required', 'string', Rule::in(array_keys($this->handles))],
            "{$at}.id" => ['bail', 'required', 'integer', 'min:1', self::ID],
        ] : $rules;
    }

    /*
     | An entry a read handed out, given back as what it is stored as. One of
     | a type the field does not name is left as it is, for the rules to
     | refuse by name. What is stored keeps only what a read shows, so a
     | reference to a type no longer named is left out.
     */
    public function complete(mixed $value, bool $stored = false): mixed
    {
        if (! $this->many) {
            $value = $this->plain($value);

            return $stored && ! $this->named($value) ? null : $value;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return $value;
        }

        $items = array_map($this->plain(...), $value);

        return $stored ? array_values(array_filter($items, $this->named(...))) : $items;
    }

    public function references(mixed $value, string $at): array
    {
        if (! $this->many) {
            return ($reference = $this->reference($value)) === null ? [] : [$at => $reference];
        }

        $references = [];

        foreach (is_array($value) && array_is_list($value) ? $value : [] as $index => $item) {
            if (($reference = $this->reference($item)) !== null) {
                $references["{$at}.{$index}"] = $reference;
            }
        }

        return $references;
    }

    /* The entries the read loaded, and one marked missing that keeps its id
       for each it did not. Anything but a reference was written around the
       layer, and is named as a column that does not parse is. */
    public function cast(mixed $value, array $loaded = []): mixed
    {
        if ($this->blank($value)) {
            return $this->many ? [] : parent::cast($value);
        }

        if (! $this->many) {
            return $this->resolve($value, $loaded);
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$this->name} holds something that is not a list.");
        }

        return array_values(array_filter(array_map(fn (mixed $item) => $this->resolve($item, $loaded), $value)));
    }

    protected function to(mixed $value): mixed
    {
        return $this->many ? array_values(array_map($this->stored(...), $value)) : $this->stored($value);
    }

    protected function json(): array
    {
        $one = $this->several() ? [
            'type' => 'object',
            'properties' => ['type' => ['enum' => array_keys($this->handles)], 'id' => ['type' => 'integer']],
            'required' => ['type', 'id'],
            'additionalProperties' => false,
        ] : ['type' => 'integer'];

        return $this->many ? ['type' => 'array', 'items' => $one] : $one;
    }

    /* Whether this points at more than one type, and so stores each
       reference with its type. */
    private function several(): bool
    {
        return count($this->to) > 1;
    }

    private function plain(mixed $item): mixed
    {
        if (! $item instanceof Entry || ($this->handles[$item::handle()] ?? null) !== $item::class) {
            return $item;
        }

        return $this->several() ? ['type' => $item::handle(), 'id' => $item->id] : $item->id;
    }

    /* Whether a stored reference is to a type the field still names. */
    private function named(mixed $item): bool
    {
        return ! $this->several() || ! is_array($item) || ! is_string($item['type'] ?? null) || isset($this->handles[$item['type']]);
    }

    /** @return array{0: class-string<Entry>, 1: int}|null */
    private function reference(mixed $item): ?array
    {
        $item = $this->plain($item);

        [$class, $id] = $this->several()
            ? [is_array($item) && is_string($item['type'] ?? null) ? $this->handles[$item['type']] ?? null : null, is_array($item) ? $item['id'] ?? null : null]
            : [$this->to[0], $item];

        $id = is_int($id) || is_string($id) ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return $class === null || $id === false ? null : [$class, $id];
    }

    private function resolve(mixed $item, array $loaded): ?Entry
    {
        if (! $this->named($item)) {
            return null;
        }

        [$class, $id] = $this->reference($item)
            ?? throw new InvalidArgumentException("{$this->name} holds something that is not ".($this->several() ? 'a type and an id' : "an entry's id").'.');

        return $loaded[$class][$id] ?? $class::reference($id);
    }

    private function stored(mixed $item): mixed
    {
        $item = $this->plain($item);

        return $this->several() ? ['type' => (string) $item['type'], 'id' => (int) $item['id']] : (int) $item;
    }
}
