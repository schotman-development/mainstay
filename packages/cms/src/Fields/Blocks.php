<?php

namespace Mainstay\Fields;

use Attribute;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Mainstay\Content\Block;
use Mainstay\Mainstay;
use ReflectionClass;
use ReflectionProperty;
use TypeError;

/*
 | An ordered list of blocks, stored as `{ id, type, data }` in a json column of
 | its own and read back as the block objects. A block's fields are checked,
 | cast and described by the same contract an entry's are, and a field of a
 | block may be Blocks itself, which is all a repeater is.
 |
 | Written as that list of arrays, or as the blocks a read handed out. A block
 | given no id, or a blank one, is given one, and keeps it.
 |
 | Translated whole or not at all, so a field inside a block is neither
 | localized nor internal. A field added to a block already in use needs a
 | default, or has to be one that reads nothing as something -- nullable, or
 | a type with an empty value: the blocks stored before it do not have it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Blocks extends Field
{
    /* The blocks whose fields are being read, so one that holds itself is
       refused rather than read without end. Static, because outside an
       application the registry that reads them is a new one per call. */
    private static array $reading = [];

    /** @var array<string, class-string<Block>> by handle */
    private array $blocks = [];

    /* Each block's class and its fields' properties, reflected once for as
       long as the registry keeps this field rather than once per block read.

       @var array<class-string<Block>, array{0: ReflectionClass, 1: array<string, ReflectionProperty>}> */
    private array $reflected = [];

    /** @param list<class-string<Block>> $of */
    public function __construct(
        ?bool $required = null,
        bool $localized = false,
        ?string $label = null,
        public readonly array $of = [],
    ) {
        if ($of === [] || ! array_is_list($of)) {
            throw new InvalidArgumentException('A blocks field lists the blocks it holds: #[Blocks(of: [Hero::class])].');
        }

        foreach ($of as $class) {
            if (! is_string($class) || ! is_subclass_of($class, Block::class) || (new ReflectionClass($class))->isAbstract()) {
                throw new InvalidArgumentException(sprintf('%s is not a block. A block is a class extending %s.', is_string($class) ? $class : get_debug_type($class), Block::class));
            }

            $class = (new ReflectionClass($class))->getName();

            /* One handle is one stored `type`, which would read back as
               whichever class came last. */
            if (($existing = $this->blocks[$class::handle()] ?? $class) !== $class) {
                throw new InvalidArgumentException("Two blocks are called \"{$class::handle()}\": {$existing} and {$class}.");
            }

            $this->blocks[$class::handle()] = $class;
        }

        parent::__construct($required, $localized, $label);
    }

    public function bind(ReflectionProperty $property): static
    {
        $this->stores($property, ['array'], 'a blocks field stores a list of blocks');

        parent::bind($property);

        foreach ($this->blocks as $class) {
            foreach ($this->fields($class) as $name => $field) {
                $because = match (true) {
                    $name === 'id' => 'is called id, which every block keeps for itself',
                    $field->localized => 'is localized, and a block is translated with the field that holds it',
                    $field->internal => 'is internal, and a block is read whole',
                    ! $field->hasDefault && ! $this->readsNothing($field) => 'has no default and no value to read a block stored without it as. Give it a default, or declare it nullable; required: true still asks every write for it',
                    default => null,
                };

                if ($because !== null) {
                    throw new InvalidArgumentException("{$class}::\${$name} {$because}.");
                }
            }
        }

        return $this;
    }

    /* Required only when the argument says so: `public array $blocks` holds
       an empty list perfectly well, and `required` refuses one. */
    public function isRequired(): bool
    {
        return $this->required === true;
    }

    protected function contradicts(): bool
    {
        return false;
    }

    protected function empty(): mixed
    {
        return [];
    }

    public function column(): array
    {
        return ['json'];
    }

    public function rules(): array
    {
        return [...parent::rules(), 'array', 'list'];
    }

    /*
     | Each item by its own block's rules, reported where it is:
     | `blocks.0.data.heading`, and a repeater's items below that. A field an
     | item was written without holds its default by now -- see complete() --
     | so the default is checked as a value given is.
     */
    public function rulesAt(string $attribute, mixed $value = null): array
    {
        $rules = parent::rulesAt($attribute, $value);

        foreach (is_array($value) && array_is_list($value) ? $value : [] as $index => $item) {
            $at = "{$attribute}.{$index}";
            $class = $this->block($item);
            $data = is_array($item) && is_array($item['data'] ?? null) ? $item['data'] : [];

            $rules[$at] = ['array:id,type,data'];
            $rules["{$at}.type"] = ['required', 'string', Rule::in(array_keys($this->blocks))];
            /* Null or blank is no id, and to() gives the block one. */
            $rules["{$at}.id"] = ['nullable', 'string', 'max:255'];

            /* Data is checked against the block its type names, and only
               the type is wrong when that is not one of these. */
            if ($class === null) {
                continue;
            }

            $fields = $this->fields($class);
            $rules["{$at}.data"] = ['sometimes', 'array:'.implode(',', array_keys($fields))];

            foreach ($fields as $name => $field) {
                $rules = [...$rules, ...$field->rulesAt("{$at}.data.{$name}", $data[$name] ?? null)];
            }
        }

        /* Last: Laravel merges a wildcard's rules into the keys already
           written, and a key written after it replaces them. Strict, or '1'
           and '01' are one id. */
        return [...$rules, "{$attribute}.*.id" => ['distinct:strict']];
    }

    /*
     | Each block's data with the default its property declares for a field
     | it was written without, all the way down, so the rules check what a
     | read would hand back. What is stored keeps only what a read shows: a
     | block of a type the field no longer lists, a key its block no longer
     | declares, is left out rather than refused, where one written is
     | refused.
     */
    public function complete(mixed $value, bool $stored = false): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $value;
        }

        $items = [];

        foreach ($value as $item) {
            if (($class = $this->block($item)) === null) {
                if (! $stored) {
                    $items[] = $item;
                }

                continue;
            }

            if (is_array($data = $item['data'] ?? [])) {
                $fields = $this->fields($class);
                $data = $stored ? array_intersect_key($data, $fields) : $data;

                foreach ($fields as $name => $field) {
                    if (array_key_exists($name, $data)) {
                        $data[$name] = $field->complete($data[$name], $stored);
                    } elseif ($field->hasDefault) {
                        $data[$name] = $field->complete($field->default);
                    }
                }

                $item['data'] = $data;
            }

            $items[] = $stored ? array_intersect_key($item, array_flip(['id', 'type', 'data'])) : $item;
        }

        return $items;
    }

    /* The images a read loaded go down to the fields inside the blocks,
       which from() alone has no way to be handed. */
    public function cast(mixed $value, array $media = []): mixed
    {
        return $this->blank($value) ? parent::cast($value) : $this->from($value, $media);
    }

    /* Each block's images where it is, `blocks.0.data.image`, by the index
       rulesAt() reports an item at. */
    public function images(mixed $value, string $at): array
    {
        $images = [];

        foreach (is_array($value) && array_is_list($value) ? $value : [] as $index => $item) {
            if (($class = $this->block($item)) === null || ! is_array($data = $item['data'] ?? [])) {
                continue;
            }

            foreach ($this->fields($class) as $name => $field) {
                $images += $field->images($data[$name] ?? null, "{$at}.{$index}.data.{$name}");
            }
        }

        return $images;
    }

    /*
     | The blocks, each field cast through its type. An item of a type the
     | field does not list -- one it has stopped listing, or something that
     | is not a block at all -- is not there, as an unknown node is not in a
     | document. A block of a type it lists is read whole or named: an id
     | that is not a string, data that is not a map, or a value its field
     | cannot read fails the read, as a column that does not parse does,
     | rather than dropping the block the next write would then delete.
     |
     | A blank id is given one, which the block keeps once the list is
     | written. A field the block was stored without keeps the default its
     | property declares, or is read from nothing -- which bind() made sure
     | every field without a default can be.
     */
    protected function from(mixed $value, array $media = []): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$this->name} holds something that is not a list of blocks.");
        }

        $blocks = [];

        foreach ($value as $item) {
            if (($class = $this->block($item)) === null) {
                continue;
            }

            /* Where the read puts it, which is where a save reports it too:
               the blocks left out ahead of it do not count. */
            $at = "the {$item['type']} at {$this->name}.".count($blocks);
            $id = $item['id'] ?? null;
            $id = $id === null || (is_string($id) && trim($id) === '') ? (string) Str::ulid() : $id;
            $data = $item['data'] ?? [];

            if (! is_string($id) || ! is_array($data)) {
                throw new InvalidArgumentException(ucfirst($at).(is_string($id) ? ' has data that is not a map.' : ' has an id that is not a string.'));
            }

            [$reflection, $properties] = $this->reflection($class);
            $block = $reflection->newInstanceWithoutConstructor();
            $block->id = $id;

            foreach ($this->fields($class) as $name => $field) {
                if (! array_key_exists($name, $data) && $field->hasDefault) {
                    continue;
                }

                try {
                    /* A tree where a scalar is kept -- a document left by a
                       field that was rich text before -- is coerced to 0 or
                       true by one type and refused by PHP for another. */
                    if (is_array($data[$name] ?? null) && ! $field->keptAsJson()) {
                        throw new InvalidArgumentException('a list or a map is stored for it.');
                    }

                    $properties[$name]->setValue($block, $field->cast($data[$name] ?? null, $media));
                } catch (InvalidArgumentException|TypeError $exception) {
                    throw new InvalidArgumentException("{$class}::\${$name} cannot read {$at}: {$exception->getMessage()}", previous: $exception);
                }
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /* The list as stored: each block's own id or a new one, its type, and
       every field serialized by its type. A field left out holds its
       default, which a save has already put there and checked. */
    protected function to(mixed $value): mixed
    {
        $items = [];

        foreach ($value as $item) {
            $item = $item instanceof Block ? $item->toArray() : $item;
            $data = $item['data'] ?? [];

            $items[] = [
                'id' => blank($item['id'] ?? null) ? (string) Str::ulid() : $item['id'],
                'type' => $item['type'],
                'data' => array_map(
                    fn (Field $field) => $field->serialize(array_key_exists($field->name, $data) ? $data[$field->name] : $field->default),
                    $this->fields($this->blocks[$item['type']]),
                ),
            ];
        }

        return $items;
    }

    protected function json(): array
    {
        return ['type' => 'array', 'items' => ['oneOf' => array_values(array_map(fn (string $class) => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
                'type' => ['const' => $class::handle()],
                'data' => app(Mainstay::class)->schema($class),
            ],
            'required' => ['id', 'type', 'data'],
            'additionalProperties' => false,
        ], $this->blocks))]];
    }

    /** @return class-string<Block>|null */
    private function block(mixed $item): ?string
    {
        return is_array($item) && is_string($item['type'] ?? null) ? $this->blocks[$item['type']] ?? null : null;
    }

    /* Whether a block stored without this field can still be read: the
       field reads nothing as null, or as its type's empty value. */
    private function readsNothing(Field $field): bool
    {
        try {
            $field->cast(null);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @return array{0: ReflectionClass, 1: array<string, ReflectionProperty>} */
    private function reflection(string $class): array
    {
        return $this->reflected[$class] ??= [
            new ReflectionClass($class),
            array_map(fn (Field $field) => new ReflectionProperty($class, $field->name), $this->fields($class)),
        ];
    }

    /** @return array<string, Field> */
    private function fields(string $class): array
    {
        if (in_array($class, self::$reading, true)) {
            throw new InvalidArgumentException("{$class} holds itself through its blocks, so there is no end to reading it.");
        }

        self::$reading[] = $class;

        try {
            return app(Mainstay::class)->fields($class);
        } finally {
            array_pop(self::$reading);
        }
    }
}
