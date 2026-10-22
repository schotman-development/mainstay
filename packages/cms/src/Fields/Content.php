<?php

namespace Mainstay\Fields;

use Attribute;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mainstay\Content\Block;
use Mainstay\Content\Document;

/*
 | An entry's content, as every WordPress post has `post_content`: a rich text
 | document with one node more, a site block at its top level, `{type:
 | 'block', attrs: {id, type, data}}`. A block's id, type and data are a
 | Blocks item's, checked, read and written by the list its type names with
 | #[Blocks] on the class, each where it is in the document:
 | `content.content.3.attrs.data.headline`. A type naming none holds text
 | alone.
 |
 | Entry declares it, and the registry hands it its type's list as it reads
 | the type.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Content extends RichText
{
    /* The blocks the type names, or null for text alone. */
    private ?Blocks $blocks = null;

    /* Whether the type has a page, where its content is built. */
    private bool $page = false;

    public function holds(?Blocks $blocks, bool $page): static
    {
        $this->blocks = $blocks?->check();
        $this->page = $page;

        return $this;
    }

    public function hasPage(): bool
    {
        return $this->page;
    }

    /** @return list<class-string<Block>> the blocks the type names */
    public function blocks(): array
    {
        return $this->blocks->of ?? [];
    }

    protected function problem(array $tree): ?string
    {
        return Document::problem($tree, blocks: $this->blocks !== null);
    }

    public function rulesAt(string $attribute, mixed $value = null, bool $draft = false): array
    {
        $rules = parent::rulesAt($attribute, $value, $draft);

        if ($this->blocks === null) {
            return $rules;
        }

        foreach ($this->nodes($value) as $index => $node) {
            if ($this->block($node)) {
                $rules = [...$rules, ...$this->blocks->itemRules("{$attribute}.content.{$index}.attrs", $node['attrs'] ?? null, $draft)];
            }
        }

        /* Last, as a Blocks field's: a key written after a wildcard replaces
           what it merged in. */
        return [...$rules, "{$attribute}.content.*.attrs.id" => ['distinct:strict']];
    }

    /*
     | Each block's data with its defaults, as a Blocks field's. What is
     | stored keeps only what a read shows: a block of a type no longer named
     | is left out, and a content left with nothing is none.
     */
    public function complete(mixed $value, bool $stored = false): mixed
    {
        if (! is_array($value) || ! is_array($value['content'] ?? null) || ! array_is_list($value['content'])) {
            return $value;
        }

        $content = [];

        foreach ($value['content'] as $node) {
            if (! $this->block($node)) {
                $content[] = $node;
            } elseif (! $stored || $this->blocks?->block($node['attrs'] ?? null) !== null) {
                $content[] = $this->blocks === null ? $node : [...$node, 'attrs' => $this->blocks->completeItem($node['attrs'] ?? null, $stored)];
            }
        }

        return $stored && $content === [] ? null : [...$value, 'content' => $content];
    }

    public function references(mixed $value, string $at): array
    {
        $references = [];

        foreach ($this->blocks === null ? [] : $this->nodes($value) as $index => $node) {
            if ($this->block($node)) {
                $references += $this->blocks->itemReferences($node['attrs'] ?? null, "{$at}.content.{$index}.attrs");
            }
        }

        return $references;
    }

    /*
     | The document, each site block in it read as its Block with what the
     | read loaded, and its node given the id the Block has: one the block
     | was stored without, or one a second block was stored with, is new, as
     | a Blocks field gives it. A block of a type not named is not there.
     */
    public function cast(mixed $value, array $loaded = []): mixed
    {
        if ($this->blank($value)) {
            return parent::cast($value);
        }

        if (! is_array($value) || ($value['type'] ?? null) !== 'doc') {
            throw new InvalidArgumentException("{$this->name} holds something that is not a document.");
        }

        $content = $blocks = [];

        foreach (is_array($value['content'] ?? null) ? $value['content'] : [] as $node) {
            if (! $this->block($node)) {
                $content[] = $node;

                continue;
            }

            if (($block = $this->blocks?->readItem($node['attrs'] ?? null, "{$this->name}.content.".count($content), $loaded)) === null) {
                continue;
            }

            if (isset($blocks[$block->id])) {
                $block->id = (string) Str::ulid();
            }

            $blocks[$block->id] = $block;
            $content[] = [...$node, 'attrs' => [...$node['attrs'], 'id' => $block->id]];
        }

        return new Document([...$value, 'content' => $content], $blocks);
    }

    /* The document as stored, each block's data serialized by its fields
       and a block with no id given one. */
    protected function to(mixed $value): mixed
    {
        $tree = parent::to($value);

        if ($this->blocks === null || ! is_array($tree) || ! is_array($tree['content'] ?? null)) {
            return $tree;
        }

        $tree['content'] = array_map(
            fn (mixed $node) => $this->block($node) && $this->blocks->block($node['attrs'] ?? null) !== null ? [...$node, 'attrs' => $this->blocks->writeItem($node['attrs'])] : $node,
            $tree['content'],
        );

        return $tree;
    }

    /** @return array<int, mixed> */
    private function nodes(mixed $value): array
    {
        $tree = $value instanceof Document ? $value->toArray() : $value;

        return is_array($tree) && is_array($tree['content'] ?? null) && array_is_list($tree['content']) ? $tree['content'] : [];
    }

    private function block(mixed $node): bool
    {
        return is_array($node) && ($node['type'] ?? null) === 'block';
    }
}
