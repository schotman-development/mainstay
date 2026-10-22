<?php

namespace Mainstay\Fields;

use Attribute;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Mainstay\Content\Block;
use Mainstay\Content\Media;
use Mainstay\Mainstay;
use ReflectionClass;
use ReflectionProperty;
use TypeError;

/*
 | The blocks a content holds, named on a class: on an entry type the ones
 | its content holds at its top level, `#[Blocks([Hero::class])]`, and on a
 | block the ones it holds inside it, its inner blocks, as a feature grid
 | holds its features. A block is stored as `{type: 'block', attrs: {id,
 | type, data}, content: [...]}`, its inner blocks its content, and read
 | back as the block object; its fields are checked, cast and described by
 | the same contract an entry's are.
 |
 | Content reads and writes each block one at a time through the item
 | methods below, which take its `attrs`. A block given no id, or a blank
 | one, is given one, and keeps it.
 |
 | Translated whole or not at all, so a field inside a block is neither
 | localized nor internal. A field added to a block already in use needs a
 | default, or has to be one that reads nothing as something -- nullable, or
 | a type with an empty value: the blocks stored before it do not have it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Blocks
{
    /** @var array<string, class-string<Block>> by handle */
    private array $blocks = [];

    /* Each block's class and its fields' properties, reflected once for as
       long as the registry keeps this list rather than once per block read.

       @var array<class-string<Block>, array{0: ReflectionClass, 1: array<string, ReflectionProperty>}> */
    private array $reflected = [];

    /** @param list<class-string<Block>> $of */
    public function __construct(public readonly array $of = [])
    {
        if ($of === [] || ! array_is_list($of)) {
            throw new InvalidArgumentException('#[Blocks] lists the blocks a content or a block holds: #[Blocks([Hero::class])].');
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
    }

    /* Each listed block's fields, refused where a block cannot hold one, and
       the blocks each holds inside it the same, all the way down, as
       `$mainstay` reads them. */
    public function check(Mainstay $mainstay): static
    {
        foreach ($this->blocks as $class) {
            foreach ($mainstay->fields($class) as $name => $field) {
                $because = match (true) {
                    $name === 'id' => 'is called id, which every block keeps for itself',
                    $field->localized => 'is localized, and a block is translated with the content that holds it',
                    $field->internal => 'is internal, and a block is read whole',
                    ! $field->hasDefault && ! $this->readsNothing($field) => 'has no default and no value to read a block stored without it as. Give it a default, or declare it nullable; required: true still asks every write for it',
                    default => null,
                };

                if ($because !== null) {
                    throw new InvalidArgumentException("{$class}::\${$name} {$because}.");
                }
            }

            $mainstay->innerBlocks($class);
        }

        return $this;
    }

    /* One item's rules, the item's `attrs` at `$at`. */
    public function itemRules(string $at, mixed $item, bool $draft = false): array
    {
        $class = $this->block($item);
        $data = is_array($item) && is_array($item['data'] ?? null) ? $item['data'] : [];

        $rules[$at] = ['array:id,type,data'];
        $rules["{$at}.type"] = ['required', 'string', Rule::in(array_keys($this->blocks))];
        /* Null or blank is no id, and writeItem() gives the block one. */
        $rules["{$at}.id"] = ['nullable', 'string', 'max:255'];

        /* Data is checked against the block its type names, and only the
           type is wrong when that is not one of these. */
        if ($class === null) {
            return $rules;
        }

        $fields = $this->fields($class);
        $rules["{$at}.data"] = ['sometimes', 'array:'.implode(',', array_keys($fields))];

        foreach ($fields as $name => $field) {
            $rules = [...$rules, ...$field->rulesAt("{$at}.data.{$name}", $data[$name] ?? null, $draft)];
        }

        return $rules;
    }

    /*
     | One item with the default its property declares for a field it was
     | written without, so the rules check what a read would hand back. What
     | is stored keeps only what a read shows: a key its block no longer
     | declares is left out rather than refused, where one written is
     | refused. One of a type not listed is as it was given, for the rules to
     | refuse, or for the caller to leave out of what is stored.
     */
    public function completeItem(mixed $item, bool $stored = false): mixed
    {
        if (($class = $this->block($item)) === null) {
            return $item;
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

        return $stored ? array_intersect_key($item, array_flip(['id', 'type', 'data'])) : $item;
    }

    /* One item as its settings post it: each field through its own
       fromForm(). A field it did not post is left out, for its default. */
    public function itemFromForm(mixed $item): mixed
    {
        if (($class = $this->block($item)) === null) {
            return $item;
        }

        $data = is_array($item['data'] ?? null) ? $item['data'] : [];

        foreach ($this->fields($class) as $name => $field) {
            if (array_key_exists($name, $data)) {
                $data[$name] = $field->fromForm($data[$name]);
            }
        }

        return [...$item, 'data' => $data];
    }

    /* What one item points at, from `$at` down: `$at.data.image`. */
    public function itemReferences(mixed $item, string $at): array
    {
        if (($class = $this->block($item)) === null || ! is_array($data = $item['data'] ?? [])) {
            return [];
        }

        $references = [];

        foreach ($this->fields($class) as $name => $field) {
            $references += $field->references($data[$name] ?? null, "{$at}.data.{$name}");
        }

        return $references;
    }

    /*
     | One item as its block, read where `$at` says, or null for one of a
     | type not listed -- one this has stopped listing, or something that is
     | not a block at all -- which is not there, as an unknown node is not in
     | a document. A block of a type it lists is read whole or named: an id
     | that is not a string, data that is not a map, or a value its field
     | cannot read fails the read, as a column that does not parse does,
     | rather than dropping the block the next write would then delete.
     |
     | A blank id is given one, which the block keeps once it is written. A
     | field the block was stored without keeps the default its property
     | declares, or is read from nothing -- which check() made sure every
     | field without a default can be. An image knows where it is, so the
     | editor can mark it.
     */
    public function readItem(mixed $item, string $at, array $loaded = []): ?Block
    {
        if (($class = $this->block($item)) === null) {
            return null;
        }

        $at = "the {$item['type']} at {$at}";
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
                /* A tree where a scalar is kept -- a document left by a field
                   that was rich text before -- is coerced to 0 or true by one
                   type and refused by PHP for another. */
                if (is_array($data[$name] ?? null) && ! $field->keptAsJson()) {
                    throw new InvalidArgumentException('a list or a map is stored for it.');
                }

                $value = $field->cast($data[$name] ?? null, $loaded);
                $properties[$name]->setValue($block, $value instanceof Media ? $value->at($id, $name) : $value);
            } catch (InvalidArgumentException|TypeError $exception) {
                throw new InvalidArgumentException("{$class}::\${$name} cannot read {$at}: {$exception->getMessage()}", previous: $exception);
            }
        }

        return $block;
    }

    /* One item as stored: its own id or a new one, its type, and every field
       serialized by its type. A field left out holds its default, which a
       save has already put there and checked. */
    public function writeItem(mixed $item): array
    {
        $item = $item instanceof Block ? $item->toArray() : $item;
        $data = $item['data'] ?? [];

        return [
            'id' => blank($item['id'] ?? null) ? (string) Str::ulid() : $item['id'],
            'type' => $item['type'],
            'data' => array_map(
                fn (Field $field) => $field->serialize(array_key_exists($field->name, $data) ? $data[$field->name] : $field->default),
                $this->fields($this->blocks[$item['type']]),
            ),
        ];
    }

    /** @return class-string<Block>|null */
    public function block(mixed $item): ?string
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
        return app(Mainstay::class)->fields($class);
    }
}
