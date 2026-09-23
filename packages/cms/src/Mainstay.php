<?php

namespace Mainstay;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Mainstay\Content\ContentType;
use Mainstay\Content\Entry;
use Mainstay\Content\Route;
use Mainstay\Database\ContentStore;
use Mainstay\Fields\Field;
use Mainstay\Fields\Internal;
use ReflectionAttribute;
use ReflectionClass;
use stdClass;

class Mainstay
{
    public const VERSION = '0.1.0';

    /** @var array<string, class-string<ContentType>> */
    private array $types = [];

    /** @var array<class-string, array<string, Field>> */
    private array $fields = [];

    /** @var array<class-string, string|array<string, string>|null> */
    private array $routes = [];

    public function version(): string
    {
        return static::VERSION;
    }

    /*
     | The host names its content types in a service provider. A directory scan
     | and a config array were the alternatives; registration wins on being the
     | same gesture the onboarding steps are added with, rather than a third
     | mechanism to learn.
     |
     | The cost, paid knowingly: the set only exists once the application has
     | booted, so schema sync and drift are artisan commands rather than
     | anything that could read the filesystem alone. They were always going to
     | be artisan commands.
     */
    public function types(array $types): void
    {
        foreach ($types as $type) {
            if (! is_string($type) || ! is_subclass_of($type, ContentType::class)) {
                throw new InvalidArgumentException(sprintf(
                    '%s is not a Mainstay content type. Extend Entry, GlobalSet or Taxonomy.',
                    is_string($type) ? $type : get_debug_type($type),
                ));
            }

            /* Its own branch, because it is its own mistake: a base class does
               extend Entry, and telling its author to do that is an answer to
               a question they did not ask. */
            if ((new ReflectionClass($type))->isAbstract()) {
                throw new InvalidArgumentException("{$type} is abstract, so there is no content to register. Register the types that extend it.");
            }

            $type = $this->canonical($type);
            $handle = $type::handle();

            /*
             | Two types answering to one handle is two classes claiming one
             | table, one URL segment and one set of capabilities. Caught at
             | registration, where the second class is named in the message,
             | rather than in phase 2 where it looks like a schema bug.
             */
            if (($existing = $this->types[$handle] ?? $type) !== $type) {
                throw new InvalidArgumentException("Two content types are called \"{$handle}\": {$existing} and {$type}.");
            }

            $this->types[$handle] = $type;
        }
    }

    /** @return array<string, class-string<ContentType>> */
    public function registered(): array
    {
        return $this->types;
    }

    /**
     | The field list: what every phase after this one reads, keyed by property
     | name and in declaration order. Nothing downstream reflects the class
     | again -- the schema plan, the form, the validator and the JSON Schema all
     | see this one list, so they cannot disagree about what a type holds.
     |
     | @return array<string, Field>
     */
    public function fields(string $type): array
    {
        $type = $this->canonical($type);

        return $this->fields[$type] ??= $this->reflect($type);
    }

    /*
     | The type's #[Route] as declared -- one pattern, or one per locale --
     | or null for a type with no URL. Checked here, the first time it is
     | asked for, so a pattern no path can be built from is refused naming
     | the type rather than surfacing as a broken lookup row.
     |
     | A pattern is `/` or a run of segments, each a lowercase slug or one
     | `{field}`. Lowercase because the lookup's unique index compares as the
     | database does, and MySQL and SQL Server fold case where SQLite and
     | Postgres do not: `/Blog/x` and `/blog/x` would be one path on two
     | drivers and two on the others. A placeholder names a field that is
     | required, so there is always something to build the path from; a
     | string, so the path is the value an editor typed rather than a date's
     | column format; and not internal, since the path is published.
     |
     | @return string|array<string, string>|null
     */
    public function route(string $type): string|array|null
    {
        $type = $this->canonical($type);

        if (array_key_exists($type, $this->routes)) {
            return $this->routes[$type];
        }

        $attributes = (new ReflectionClass($type))->getAttributes(Route::class);
        $route = $attributes === [] ? null : $attributes[0]->newInstance()->pattern;

        if (is_array($route) && ($route === [] || array_is_list($route))) {
            throw new InvalidArgumentException("{$type}'s #[Route] is a list. Give one pattern, or a pattern per locale keyed by the locale.");
        }

        foreach ((array) $route as $pattern) {
            $this->pattern($type, $pattern);
        }

        return $this->routes[$type] = $route;
    }

    private function pattern(string $type, mixed $pattern): void
    {
        if (! is_string($pattern) || ($pattern !== '/' && ! preg_match('#\A(?:/(?:[a-z0-9]+(?:-[a-z0-9]+)*|\{\w+\}))+\z#', $pattern))) {
            throw new InvalidArgumentException(sprintf(
                "%s's #[Route] pattern %s is not a path Mainstay can store: it starts with /, has no trailing slash, and each segment is a lowercase slug or one {field}.",
                $type,
                is_string($pattern) ? "\"{$pattern}\"" : get_debug_type($pattern),
            ));
        }

        preg_match_all('/\{(\w+)\}/', $pattern, $names);

        foreach ($names[1] as $name) {
            $field = $this->fields($type)[$name] ?? throw new InvalidArgumentException("{$type}'s #[Route] pattern \"{$pattern}\" names {{$name}}, which is not a field of the type.");

            $because = match (true) {
                $field->internal => 'is internal, and the path is published',
                $field->phpType !== 'string' => "is typed {$field->phpType}, and a path is built from strings",
                ! $field->isRequired() => 'is optional, and a path cannot be built from nothing',
                default => null,
            };

            if ($because !== null) {
                throw new InvalidArgumentException("{$type}'s #[Route] pattern \"{$pattern}\" names {{$name}}, which {$because}.");
            }
        }
    }

    /* The type as JSON Schema, which phase 11 serves from a discovery endpoint
       and writes out as a `.d.ts` -- from here rather than derived twice. */
    public function schema(string $type): array
    {
        $fields = $this->fields($type);

        return [
            'type' => 'object',
            'title' => class_basename($type),
            /* An empty PHP array encodes as `[]`, and JSON Schema wants an
               object there -- ajv in strict mode and the .d.ts generator both
               refuse the array. */
            'properties' => array_map(fn (Field $field) => $field->schema(), $fields) ?: new stdClass,
            /*
             | Every key, because JSON Schema's `required` asks whether the key
             | is present in the object, not whether an editor has to fill the
             | box. Nullability already carries the second question, so
             | filtering by isRequired() here states it twice and generates
             | `summary?: string | null` for a key the payload always has.
             */
            'required' => array_keys($fields),
            'additionalProperties' => false,
        ];
    }

    /*
     | The query layer, reached from here so a template writes
     | `Mainstay::find(Article::class, where: [...])`. The arguments are
     | ContentStore's, passed through by name.
     */
    public function find(string $type, mixed ...$arguments): Collection
    {
        return $this->store()->find($type, ...$arguments);
    }

    public function findById(string $type, mixed ...$arguments): ?Entry
    {
        return $this->store()->findById($type, ...$arguments);
    }

    public function paginate(string $type, mixed ...$arguments): LengthAwarePaginator
    {
        return $this->store()->paginate($type, ...$arguments);
    }

    private function store(): ContentStore
    {
        return new ContentStore($this);
    }

    /* `\App\Article` and `App\Article` are one class and two cache keys -- and
       two field lists that could disagree, which is the one thing fields()
       promises cannot happen. A type registers its handle from here for the
       same reason. Case is left alone, and cannot usefully be otherwise: PHP's
       class table is case-insensitive but PSR-4 is not, so `app\article` throws
       until something has loaded the class under its real name and resolves
       afterwards. Not a spelling to write. */
    private function canonical(string $type): string
    {
        /* fields() and schema() are the two entry points a host reaches with a
           class name it typed, so a typo answers here in the same currency
           types() pays in -- and not as the ReflectionException that no caller
           guarding registration is catching for. */
        if (! class_exists($type)) {
            throw new InvalidArgumentException("{$type} is not a class Mainstay can reflect.");
        }

        return (new ReflectionClass($type))->getName();
    }

    private function reflect(string $type): array
    {
        $fields = [];
        $reflection = new ReflectionClass($type);

        /*
         | Base classes first. getProperties() answers with a class's own
         | properties before the ones it inherits, which would file a shared
         | base's title after everything the child adds -- an order the
         | declaration does not show anywhere, on a list phase 10 draws the form
         | from. A property a child redeclares keeps the base's position and
         | takes the child's attribute -- assignment by name overwrites in
         | place, which is also why a trait's property, seen again on the class
         | that uses it, keeps the slot the trait gave it.
         */
        foreach ($this->hierarchy($type) as $class) {
            foreach ($class->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                $attributes = $property->getAttributes(Field::class, ReflectionAttribute::IS_INSTANCEOF);

                /* A property without a field attribute is the type's own
                   business, not a field the admin should be drawing. */
                if ($attributes === []) {
                    /* Unless it says it is hiding something: a flag with no
                       field under it hides nothing, and reads as if it does.
                       A base's field widened here is one to hide. */
                    if ($property->getAttributes(Internal::class) !== [] && ! $this->fielded($class, $property->getName())) {
                        throw new InvalidArgumentException("{$type}::\${$property->getName()} is marked #[Internal] and carries no field attribute, so there is no field for it to hide. Put the field attribute beside it.");
                    }

                    continue;
                }

                /* All three of these are a declaration that reads as if it
                   works. Silently taking the first attribute, or silently
                   skipping a field because it is not public or not an
                   instance property, is a field an editor never sees and
                   nothing anywhere reports. */
                if (count($attributes) > 1) {
                    throw new InvalidArgumentException("{$type}::\${$property->getName()} has more than one field attribute on it.");
                }

                /*
                 | The declaration an entry actually holds. A class is read on
                 | its own here, so a base's `protected string $title` is the
                 | one these guards saw even where the child redeclares it
                 | public -- PHP allows the widening, and the field was refused
                 | for the mistake it fixes. The attribute stays the one this
                 | loop found, which is the child's where the child wrote one.
                 |
                 | A private declaration is left alone: a child's property of
                 | the same name is a second slot rather than the same one
                 | widened, and the field is on the slot nothing outside the
                 | base can write. Resolving by name would bind it to the
                 | other one, which is a silent wrong column where the guard
                 | below is a refusal naming the property.
                 */
                if (! $property->isPrivate() && $reflection->hasProperty($property->getName())) {
                    $property = $reflection->getProperty($property->getName());
                }

                if (! $property->isPublic()) {
                    throw new InvalidArgumentException("{$type}::\${$property->getName()} carries a field attribute but is not public.");
                }

                /* A field is a value an entry holds, and a static property is
                   one the class holds -- there is no row for it to be a
                   column of. */
                if ($property->isStatic()) {
                    throw new InvalidArgumentException("{$type}::\${$property->getName()} carries a field attribute but is static.");
                }

                $field = $attributes[0]->newInstance()->bind($property);

                $fields[$field->name] = $field;
            }
        }

        return $fields;
    }

    /* Whether a class this one extends carries a field attribute on the
       property of that name. */
    private function fielded(ReflectionClass $class, string $name): bool
    {
        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if ($parent->hasProperty($name) && $parent->getProperty($name)->getAttributes(Field::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                return true;
            }
        }

        return false;
    }

    /*
     | Root-first, and each class's traits ahead of the class itself: a trait is
     | a horizontal base, so `use HasSeo` written at the top of a body should
     | put its fields at the top of the form, the same way a parent's lead. PHP
     | files trait properties after the ones the class declares itself, which is
     | an order the declaration shows nowhere.
     |
     | One level of traits. A trait using a trait sorts by PHP's order until
     | something actually declares fields that way.
     |
     | @return list<ReflectionClass>
     */
    private function hierarchy(string $type): array
    {
        $classes = [];

        for ($class = new ReflectionClass($type); $class !== false; $class = $class->getParentClass()) {
            $classes = [...array_values($class->getTraits()), $class, ...$classes];
        }

        return $classes;
    }
}
