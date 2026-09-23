<?php

namespace Mainstay\Content;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

abstract class ContentType
{
    /*
     | What the query layer fills beside the fields: the row, the locale it was
     | read in, who owns it, and when it was written, in UTC. Not fields -- no
     | attribute, so no form draws them and no declaration can claim their
     | names, which the schema reserves. Nobody owns anything before phase 9.
     */
    public int $id;

    public ?int $ownerId;

    public string $locale;

    public ?CarbonImmutable $createdAt;

    public ?CarbonImmutable $updatedAt;

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
