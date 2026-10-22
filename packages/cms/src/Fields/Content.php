<?php

namespace Mainstay\Fields;

use Attribute;
use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mainstay\Content\Block;
use Mainstay\Content\Document;
use Mainstay\Mainstay;

/*
 | An entry's content, as every WordPress post has `post_content`: a rich text
 | document with one node more, a site block at its top level, `{type:
 | 'block', attrs: {id, type, data}}`, holding the blocks inside it as its
 | content where its class names them. A block's id, type and data are
 | checked, read and written by the #[Blocks] list that names it -- its
 | type's for one at the top, the block's around it for one inside -- each
 | where it is in the document: `content.content.3.attrs.data.headline`,
 | `content.content.3.content.0.attrs.data.title`. A type naming none holds
 | text alone.
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
        $this->blocks = $blocks;
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

    /* The list the type names, which the blocks at the content's top level
       are read through. */
    public function listing(): ?Blocks
    {
        return $this->blocks;
    }

    protected function problem(array $tree): ?string
    {
        return Document::problem($tree, blocks: $this->blocks !== null);
    }

    public function rulesAt(string $attribute, mixed $value = null, bool $draft = false): array
    {
        $rules = parent::rulesAt($attribute, $value, $draft);

        return $this->blocks === null ? $rules : [...$rules, ...$this->nodesRules($attribute, $this->nodes($value), $this->blocks, $draft)];
    }

    /*
     | Each block of `$nodes`, the content of the node at `$at`, by its list's
     | rules, and the blocks inside it by its own -- or refused, for a block
     | that holds none. Last, as Laravel wants it: a key written after a
     | wildcard replaces what it merged in.
     */
    private function nodesRules(string $at, array $nodes, Blocks $list, bool $draft): array
    {
        $rules = [];

        foreach ($nodes as $index => $node) {
            if (! $this->block($node)) {
                continue;
            }

            $place = "{$at}.content.{$index}";
            $rules = [...$rules, ...$list->itemRules("{$place}.attrs", $node['attrs'] ?? null, $draft)];
            $class = $list->block($node['attrs'] ?? null);
            $inner = $class === null ? null : app(Mainstay::class)->innerBlocks($class);

            if ($inner !== null) {
                $rules = [...$rules, ...$this->nodesRules($place, $this->nodes($node), $inner, $draft)];
            } elseif ($class !== null) {
                $rules["{$place}.content"] = [function (string $attribute, mixed $value, Closure $fail) use ($class) {
                    if ($value !== [] && $value !== null) {
                        $fail(class_basename($class).' holds no blocks inside it.');
                    }
                }];
            }
        }

        return [...$rules, "{$at}.content.*.attrs.id" => ['distinct:strict']];
    }

    /*
     | Each block's data with its defaults, the blocks inside it too. What is
     | stored keeps only what a read shows: a block of a type its list no
     | longer names is left out, and a content left with nothing is none.
     */
    public function complete(mixed $value, bool $stored = false): mixed
    {
        if (! is_array($value) || ! is_array($value['content'] ?? null) || ! array_is_list($value['content'])) {
            return $value;
        }

        $content = $this->completeNodes($value['content'], $this->blocks, $stored);

        return $stored && $content === [] ? null : [...$value, 'content' => $content];
    }

    private function completeNodes(array $nodes, ?Blocks $list, bool $stored): array
    {
        $content = [];

        foreach ($nodes as $node) {
            if (! $this->block($node)) {
                $content[] = $node;

                continue;
            }

            if ($stored && $list?->block($node['attrs'] ?? null) === null) {
                continue;
            }

            if ($list !== null) {
                $class = $list->block($node['attrs'] ?? null);
                $node = [...$node, 'attrs' => $list->completeItem($node['attrs'] ?? null, $stored)];

                if (is_array($node['content'] ?? null) && array_is_list($node['content'])) {
                    $node['content'] = $this->completeNodes($node['content'], $class === null ? null : app(Mainstay::class)->innerBlocks($class), $stored);
                }

                if ($stored && ($node['content'] ?? null) === []) {
                    unset($node['content']);
                }
            }

            $content[] = $node;
        }

        return $content;
    }

    public function references(mixed $value, string $at): array
    {
        return $this->blocks === null ? [] : $this->nodesReferences($this->nodes($value), $this->blocks, $at);
    }

    private function nodesReferences(array $nodes, Blocks $list, string $at): array
    {
        $references = [];

        foreach ($nodes as $index => $node) {
            if (! $this->block($node)) {
                continue;
            }

            $references += $list->itemReferences($node['attrs'] ?? null, "{$at}.content.{$index}.attrs");

            if (($class = $list->block($node['attrs'] ?? null)) !== null && ($inner = app(Mainstay::class)->innerBlocks($class)) !== null) {
                $references += $this->nodesReferences($this->nodes($node), $inner, "{$at}.content.{$index}");
            }
        }

        return $references;
    }

    /*
     | The document, each site block in it read as its Block with what the
     | read loaded, the blocks inside it as its inner blocks, and each node
     | given the id its Block has: one the block was stored without, or one
     | another block was stored with anywhere in the document, is new. A
     | block of a type its list does not name is not there.
     */
    public function cast(mixed $value, array $loaded = []): mixed
    {
        if ($this->blank($value)) {
            return parent::cast($value);
        }

        if (! is_array($value) || ($value['type'] ?? null) !== 'doc') {
            throw new InvalidArgumentException("{$this->name} holds something that is not a document.");
        }

        $blocks = [];
        [$content] = $this->readNodes(is_array($value['content'] ?? null) ? $value['content'] : [], $this->blocks, $this->name, $loaded, $blocks, text: true);

        return new Document([...$value, 'content' => $content], $blocks);
    }

    /**
     * The nodes as read, and the blocks among them, every block read into
     * `$blocks` by its id.
     *
     * @param  array<string, Block>  $blocks
     * @return array{0: list<mixed>, 1: list<Block>}
     */
    private function readNodes(array $nodes, ?Blocks $list, string $at, array $loaded, array &$blocks, bool $text = false): array
    {
        $content = $read = [];

        foreach ($nodes as $node) {
            if (! $this->block($node)) {
                if ($text) {
                    $content[] = $node;
                }

                continue;
            }

            $place = "{$at}.content.".count($content);

            if (($block = $list?->readItem($node['attrs'] ?? null, $place, $loaded)) === null) {
                continue;
            }

            if (isset($blocks[$block->id])) {
                $block->id = (string) Str::ulid();
            }

            $blocks[$block->id] = $block;
            [$inner, $held] = $this->readNodes($this->nodes($node), app(Mainstay::class)->innerBlocks($block::class), $place, $loaded, $blocks);
            $node = [...$node, 'attrs' => [...$node['attrs'], 'id' => $block->id]];
            unset($node['content']);
            $content[] = $inner === [] ? $node : [...$node, 'content' => $inner];
            $read[] = $block->holding($held);
        }

        return [$content, $read];
    }

    /* The document as stored, each block's data serialized by its fields,
       the blocks inside it too, and a block with no id given one. */
    protected function to(mixed $value): mixed
    {
        $tree = parent::to($value);

        if ($this->blocks === null || ! is_array($tree) || ! is_array($tree['content'] ?? null)) {
            return $tree;
        }

        $tree['content'] = $this->writeNodes($tree['content'], $this->blocks);

        return $tree;
    }

    private function writeNodes(array $nodes, ?Blocks $list): array
    {
        return array_map(function (mixed $node) use ($list) {
            if (! $this->block($node) || ($class = $list?->block($node['attrs'] ?? null)) === null) {
                return $node;
            }

            $node = [...$node, 'attrs' => $list->writeItem($node['attrs'])];

            return is_array($node['content'] ?? null) ? [...$node, 'content' => $this->writeNodes($node['content'], app(Mainstay::class)->innerBlocks($class))] : $node;
        }, $nodes);
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
