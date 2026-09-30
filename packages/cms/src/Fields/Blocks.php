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

/*
 | An ordered list of blocks, stored as `{ id, type, data }` in a json column of
 | its own and read back as the block objects. A block's fields are checked,
 | cast and described by the same contract an entry's are, and a field of a
 | block may be Blocks itself, which is all a repeater is.
 |
 | Written as that list of arrays, or as the blocks a read handed out. A block
 | given no id is given one, and keeps it.
 |
 | Translated whole or not at all, so a field inside a block is neither
 | localized nor internal. A field added to a block already in use needs a
 | default or has to be nullable: the blocks stored before it do not have it.
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
     | `blocks.0.data.heading`, and a repeater's items below that.
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
            $rules["{$at}.id"] = ['sometimes', 'string', 'max:255'];

            /* Data is checked against the block its type names, and only
               the type is wrong when that is not one of these. */
            if ($class === null) {
                continue;
            }

            $fields = $this->fields($class);
            $rules["{$at}.data"] = ['sometimes', 'array:'.implode(',', array_keys($fields))];

            foreach ($fields as $name => $field) {
                $nested = $field->rulesAt("{$at}.data.{$name}", $data[$name] ?? null);

                /* Left out, it takes the default its property declares, as
                   an entry's field does. */
                if ((new ReflectionProperty($class, $name))->hasDefaultValue()) {
                    array_unshift($nested["{$at}.data.{$name}"], 'sometimes');
                }

                $rules = [...$rules, ...$nested];
            }
        }

        /* Last: Laravel merges a wildcard's rules into the keys already
           written, and a key written after it replaces them. */
        return [...$rules, "{$attribute}.*.id" => ['distinct']];
    }

    /*
     | The blocks, each field cast through its type. An item this cannot read
     | -- a type the field no longer lists, a shape that is not a block's -- is
     | not there, as an unknown node is not in a document. A field an item was
     | stored without keeps the default its property declares, or is cast from
     | nothing.
     */
    protected function from(mixed $value): mixed
    {
        $blocks = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (($class = $this->block($item)) === null || ! is_string($item['id'] ?? null) || ! is_array($data = $item['data'] ?? [])) {
                continue;
            }

            $block = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $block->id = $item['id'];

            foreach ($this->fields($class) as $name => $field) {
                $property = new ReflectionProperty($class, $name);

                if (array_key_exists($name, $data) || ! $property->hasDefaultValue()) {
                    $property->setValue($block, $field->cast($data[$name] ?? null));
                }
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /* The list as stored: each block's own id or a new one, its type, and
       every field serialized by its type. */
    protected function to(mixed $value): mixed
    {
        $items = [];

        foreach ($value as $item) {
            $item = $item instanceof Block ? $item->toArray() : $item;
            $class = $this->blocks[$item['type']];
            $data = $item['data'] ?? [];

            $items[] = [
                'id' => $item['id'] ?? (string) Str::ulid(),
                'type' => $item['type'],
                'data' => array_map(
                    fn (Field $field) => $field->serialize(array_key_exists($field->name, $data) ? $data[$field->name] : $this->default($class, $field->name)),
                    $this->fields($class),
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

    private function default(string $class, string $name): mixed
    {
        $property = new ReflectionProperty($class, $name);

        return $property->hasDefaultValue() ? $property->getDefaultValue() : null;
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
