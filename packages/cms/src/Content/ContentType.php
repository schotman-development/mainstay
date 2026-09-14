<?php

namespace Mainstay\Content;

use Illuminate\Support\Str;

abstract class ContentType
{
    /*
     | The name this type is known by everywhere it is not a class: its table,
     | its capability strings, its segment of an admin URL. Derived once here
     | rather than in each of them, because four derivations of the same name
     | are four chances for `blog_post` and `blogpost` to both exist.
     */
    public static function handle(): string
    {
        return Str::snake(class_basename(static::class));
    }
}
