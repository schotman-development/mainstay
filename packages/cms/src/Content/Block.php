<?php

namespace Mainstay\Content;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Mainstay\Fields\Field;
use Mainstay\Mainstay;

/*
 | One block of a content: a class whose properties carry the same field
 | attributes an entry's do, read and written through the #[Blocks] list
 | that names it -- an entry type's, or the block's that holds it. The blocks
 | themselves are the site's to write, and so are the views that draw them.
 |
 | Htmlable, drawn by the view `blocks.{handle}` with the block as `$block`,
 | so a template prints a content's with `@foreach ($entry->content->blocks()
 | as $block) {{ $block }} @endforeach`. A view that is not there is an
 | error, as an entry's is. A block whose class names blocks with #[Blocks]
 | holds them inside it, its inner blocks, which its view prints with
 | `@inner($block)`.
 */
abstract class Block implements Arrayable, Htmlable
{
    /* Given by the layer when the block is first written, and kept: what
       tells a block moved from a block replaced. */
    public string $id;

    /* Private, so a block's own field of this name is a slot of its own. */
    private array $inner = [];

    /** @return list<Block> the blocks inside this one, in their order */
    public function inner(): array
    {
        return $this->inner;
    }

    /**
     * The content's read hands a block its inner blocks.
     *
     * @internal
     *
     * @param  list<Block>  $blocks
     */
    public function holding(array $blocks): static
    {
        $this->inner = $blocks;

        return $this;
    }

    /* What a stored block's `type` says, and its view's name, so one block's
       alone: two blocks of one handle are refused, in whichever fields. */
    public static function handle(): string
    {
        return Str::snake(class_basename(static::class));
    }

    public function toHtml(): string
    {
        return view('blocks.'.static::handle(), ['block' => $this])->render();
    }

    /* The block as a save is given one: its id once it has one, its type,
       and what each field holds, which the save checks before anything is
       written. A field not set, on a block built by hand, holds nothing. */
    public function toArray(): array
    {
        return [
            ...(isset($this->id) ? ['id' => $this->id] : []),
            'type' => static::handle(),
            'data' => array_map(fn (Field $field) => $this->{$field->name} ?? null, app(Mainstay::class)->fields(static::class)),
        ];
    }
}
