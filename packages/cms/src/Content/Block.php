<?php

namespace Mainstay\Content;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Mainstay\Fields\Field;
use Mainstay\Mainstay;

/*
 | One block in a Blocks field: a class whose properties carry the same field
 | attributes an entry's do, read and written through the field that lists it.
 | The blocks themselves are the site's to write, and so are the views that
 | draw them.
 |
 | Htmlable, drawn by the view `blocks.{handle}` with the block as `$block`,
 | so a template prints a list with `@foreach ($entry->blocks as $block)
 | {{ $block }} @endforeach`. A view that is not there is an error, as an
 | entry's is.
 */
abstract class Block implements Arrayable, Htmlable
{
    /* Given by the layer when the block is first written, and kept: what
       tells a block moved from a block replaced. */
    public string $id;

    /* What a stored block's `type` says, and its view's name. */
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
