<?php

namespace Mainstay;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Mainstay\Content\Block;
use Mainstay\Content\ContentType;
use Mainstay\Content\Entry;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\Route;
use Mainstay\Content\Template;
use Mainstay\Database\ContentStore;
use Mainstay\Fields\Field;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Select;
use Mainstay\Fields\Terms;
use Mainstay\Media\Library;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

class Mainstay
{
    public const VERSION = '0.1.0';

    /** @var array<string, class-string<ContentType>> */
    private array $types = [];

    /** @var array<class-string, array<string, Field>> */
    private array $fields = [];

    /** @var array<string, class-string<Block>> by handle */
    private array $blocks = [];

    /** @var array<class-string, string|array<string, string>|null> */
    private array $routes = [];

    /** @var array{0: mixed, 1: array<string, array{origin: string, host: ?string, prefix: string}>}|null */
    private ?array $locales = null;

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
        /* Asked once per block read, and by then usually spelled as it was
           reflected, so a known name skips the reflection canonical() does. */
        if (isset($this->fields[$type])) {
            return $this->fields[$type];
        }

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
        if (! is_string($pattern) || ($pattern !== '/' && ! preg_match('#\A(?:/(?:'.Route::SEGMENT.'|'.Route::PLACEHOLDER.'))+\z#', $pattern))) {
            throw new InvalidArgumentException(sprintf(
                "%s's #[Route] pattern %s is not a path Mainstay can store: it starts with /, has no trailing slash, and each segment is a lowercase slug or one {field}.",
                $type,
                is_string($pattern) ? "\"{$pattern}\"" : get_debug_type($pattern),
            ));
        }

        preg_match_all('/'.Route::PLACEHOLDER.'/', $pattern, $names);

        foreach ($names[1] as $name) {
            $field = $this->fields($type)[$name] ?? throw new InvalidArgumentException("{$type}'s #[Route] pattern \"{$pattern}\" names {{$name}}, which is not a field of the type.");

            $because = match (true) {
                $field->internal => 'is internal, and the path is published',
                $field->phpType !== 'string' => "is typed {$field->phpType}, and a path is built from strings",
                ! $field->isRequired() => 'is optional, and a path cannot be built from nothing',
                /* Every write choosing one would be refused for it. */
                $field instanceof Select && ($option = $this->unrouted($field)) !== null => "offers \"{$option}\", which is not a path segment",
                default => null,
            };

            if ($because !== null) {
                throw new InvalidArgumentException("{$type}'s #[Route] pattern \"{$pattern}\" names {{$name}}, which {$because}.");
            }
        }
    }

    /* A select's first option that cannot be a segment of a path. */
    private function unrouted(Select $field): ?string
    {
        foreach ($field->values() as $value) {
            if (! preg_match(Route::SLUG, $value)) {
                return $value;
            }
        }

        return null;
    }

    /*
     | The type as JSON Schema, which phase 11 serves from a discovery endpoint
     | and writes out as a `.d.ts` -- from here rather than derived twice.
     |
     | It describes what a reader is handed, so an internal field is in it
     | only for a reader who may see those, `$internal`. For any other it is
     | neither required nor named: a payload the query layer hands such a
     | reader never holds it, and would fail a schema that required it beside
     | `additionalProperties: false`, and the schema would tell every consumer
     | the field is there.
     */
    public function schema(string $type, bool $internal = false): array
    {
        $fields = array_filter($this->fields($type), fn (Field $field) => $internal || ! $field->internal);

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

    public function findByUri(string $uri, mixed ...$arguments): ?Entry
    {
        return $this->store()->findByUri($uri, ...$arguments);
    }

    public function paginate(string $type, mixed ...$arguments): LengthAwarePaginator
    {
        return $this->store()->paginate($type, ...$arguments);
    }

    public function create(string $type, mixed ...$arguments): Entry
    {
        return $this->store()->create($type, ...$arguments);
    }

    public function update(string $type, mixed ...$arguments): Entry
    {
        return $this->store()->update($type, ...$arguments);
    }

    public function delete(string $type, mixed ...$arguments): void
    {
        $this->store()->delete($type, ...$arguments);
    }

    /* A global, `Mainstay::global(Footer::class)`: this site's one, in the
       locale read, or null where it has not been written in it. */
    public function global(string $type, mixed ...$arguments): ?GlobalSet
    {
        return $this->store()->global($type, ...$arguments);
    }

    public function saveGlobal(string $type, mixed ...$arguments): GlobalSet
    {
        return $this->store()->saveGlobal($type, ...$arguments);
    }

    private function store(): ContentStore
    {
        return new ContentStore($this);
    }

    /* The media library: `Mainstay::media()->upload(...)`, beside the
       entries rather than among them, since an image is no type's. */
    public function media(): Library
    {
        return new Library($this);
    }

    /*
     | The view an entry renders with: its own, then its type's #[Template],
     | then the type's handle. The first one given wins, not the first one
     | there is -- a view named and missing is an error, rather than a quiet
     | step down to one nobody chose.
     */
    public function template(Entry $entry): string
    {
        return (new ReflectionProperty(Entry::class, 'template'))->getValue($entry) ?? $this->templates($entry::class)[0];
    }

    /*
     | The views a type's entries may render with, the one they render with
     | first: its #[Template]'s, or its handle alone.
     |
     | @return list<string>
     */
    public function templates(string $type): array
    {
        $attributes = (new ReflectionClass($type))->getAttributes(Template::class);

        return $attributes === [] ? [$type::handle()] : $attributes[0]->newInstance()->views;
    }

    /*
     | The content locales, the default first, each with where it is served:
     | a path prefix, `'/nl'`, a host, `'https://example.nl'`, or both. `/` is
     | the unprefixed one on every host.
     |
     | Either every locale names a host or none does. One without a host
     | answers on all of them, so beside one with a host it would link to
     | itself on whichever host the page it is linked from is on.
     |
     | @return array<string, array{origin: string, host: ?string, prefix: string}>
     */
    public function locales(): array
    {
        $config = config('mainstay.locales');

        /* Parsed once for the config it was parsed from: nearly every read,
           write and link asks. */
        if ($this->locales !== null && $this->locales[0] === $config) {
            return $this->locales[1];
        }

        /* A list reads as locales called 0 and 1, served at `en` and `nl`. */
        if (! is_array($config) || $config === [] || array_is_list($config)) {
            throw new InvalidArgumentException("mainstay.locales maps each content locale to where it is served, the default first: ['en' => '/', 'nl' => '/nl'], or ['en' => 'https://example.com', 'nl' => 'https://example.nl'].");
        }

        $locales = $bases = [];

        foreach ($config as $locale => $base) {
            /* The prefix held to what a path is, lowercase, for the reason a
               path is: MySQL and SQL Server would match `/NL` to it and the
               others would not. */
            if (! is_string($locale) || ! is_string($base) || ! preg_match('#\A(https?://([A-Za-z0-9.-]+)(?::\d+)?)?((?:'.Route::PATH.')?)/?\z#', $base, $parts)) {
                throw new InvalidArgumentException(sprintf(
                    'mainstay.locales serves %s at %s, which is neither a path of lowercase segments, like /nl, nor a URL, like https://example.nl.',
                    $locale,
                    is_string($base) ? "\"{$base}\"" : get_debug_type($base),
                ));
            }

            $host = $parts[2] === '' ? null : strtolower($parts[2]);

            if (($other = $bases[$host.$parts[3]] ?? null) !== null) {
                throw new InvalidArgumentException("mainstay.locales serves {$other} and {$locale} at the same base, so a request cannot tell them apart.");
            }

            $bases[$host.$parts[3]] = $locale;
            $locales[$locale] = ['origin' => $parts[1], 'host' => $host, 'prefix' => $parts[3]];
        }

        if (count(array_unique(array_map(fn (array $base) => $base['host'] === null, $locales))) > 1) {
            throw new InvalidArgumentException('mainstay.locales gives some locales a host and not others. One without a host answers on every host, so give each locale a host or none of them.');
        }

        return ($this->locales = [$config, $locales])[1];
    }

    /*
     | The locale a request is in and its path with the locale's prefix taken
     | off, which is what the lookup holds. The longest prefix wins, and a
     | prefix matches whole segments: `/nlx` is not under `/nl`. A host is
     | compared without scheme or port, which a proxy in front of the
     | application may have changed. Null where no locale is served.
     |
     | @return array{0: string, 1: string}|null
     */
    public function resolve(string $host, string $path): ?array
    {
        $path = '/'.trim($path, '/');
        $found = null;

        foreach ($this->locales() as $locale => $base) {
            if (($base['host'] === null || $base['host'] === strtolower($host)) && $this->under($path, $base['prefix'])
                && ($found === null || strlen($base['prefix']) > strlen($found[1]))) {
                $found = [$locale, $base['prefix']];
            }
        }

        return $found === null ? null : [$found[0], substr($path, strlen($found[1])) ?: '/'];
    }

    /*
     | The link to an entry: its locale's base and its path, with the
     | subdirectory the application is served from between them, since a
     | request's path is read below it. For a locale with no host, all of it
     | through the URL generator, which puts the request's host on as well.
     |
     | Null for a type with no #[Route], and for an entry not read with its
     | locale and path: one handed to a caller that may write the type but
     | not read it, the one a policy is shown, or one never read at all.
     */
    public function url(Entry $entry): ?string
    {
        if (! isset($entry->locale, $entry->uri)) {
            return null;
        }

        $base = $this->locales()[$entry->locale];
        $path = $this->path($base['prefix'], $entry->uri);

        return $base['host'] === null ? URL::to($path) : $base['origin'].parse_url(URL::to('/'), PHP_URL_PATH).$path;
    }

    /*
     | What a request for a locale's path reaches instead of the entry that
     | holds it, or null when nothing does: a route of Mainstay's own, or
     | another locale whose prefix the path runs into -- `/nl` in an
     | unprefixed English beside a Dutch at `/nl`.
     |
     | The routes are asked of the router, which is what answers the request:
     | every one named `mainstay.*` but the catch-all, so the admin at any
     | path, the API, and whatever the package adds are one check. Not the
     | host's own, which may be reading the same entry on purpose. A locale
     | with no host is served on every host, and a route kept to a domain
     | takes only one of them, so for such a locale it is left out.
     */
    public function shadow(string $locale, string $uri): ?string
    {
        $base = $this->locales()[$locale];
        $path = $this->path($base['prefix'], $uri);
        $request = Request::create(($base['host'] === null ? '' : "http://{$base['host']}").$path);

        foreach (app('router')->getRoutes()->get('GET') as $route) {
            if (str_starts_with((string) $route->getName(), 'mainstay.') && ! $route->isFallback
                && ($base['host'] !== null || $route->getDomain() === null) && $route->matches($request)) {
                return "the route {$route->getName()}";
            }
        }

        $reached = $this->resolve($base['host'] ?? '', $path)[0];

        return $reached === $locale ? null : "{$reached}'s prefix {$this->locales()[$reached]['prefix']}";
    }

    /* A locale's path under its prefix: `/nl` for its `/`, not `/nl/`. */
    private function path(string $prefix, string $uri): string
    {
        return $prefix !== '' && $uri === '/' ? $prefix : $prefix.$uri;
    }

    private function under(string $path, string $prefix): bool
    {
        return $prefix === '' || $path === $prefix || str_starts_with($path, "{$prefix}/");
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
        /*
         | A block's handle names the view that draws it, whichever field lists
         | it, so two blocks of one handle in two fields would draw with one
         | view -- one of them with markup written for the other's properties.
         | Recorded before its fields are read, so a block holding another of
         | its own handle is caught as well; and every block a field lists is
         | read here, since Blocks reads each one as it is bound.
         */
        if (is_subclass_of($type, Block::class)) {
            if (($read = $this->blocks[$type::handle()] ?? $type) !== $type) {
                throw new InvalidArgumentException("Two blocks are called \"{$type::handle()}\": {$read} and {$type}, and a block's handle names the view that draws it. Give one of them a handle() of its own.");
            }

            $this->blocks[$type::handle()] = $type;
        }

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

        $this->terms($type, $fields);

        return $fields;
    }

    /*
     | Terms on an entry only, one field per taxonomy. A term's page lists the
     | entries holding it, so a block's terms are ones it could not see and a
     | global's ones it has nothing to list; and the pivot says which entry a
     | row is for, not which of its fields.
     */
    private function terms(string $type, array $fields): void
    {
        $taxonomies = [];

        foreach ($fields as $name => $field) {
            if (! $field instanceof Terms) {
                continue;
            }

            if (! is_subclass_of($type, Entry::class)) {
                throw new InvalidArgumentException("{$type}::\${$name} is a terms field, and only an entry holds terms: a term's page lists the entries holding it. Point at the terms with a relation instead.");
            }

            if (($other = $taxonomies[$field->of] ?? null) !== null) {
                throw new InvalidArgumentException("{$type}::\${$other} and \${$name} both hold {$field->of}'s terms, and its pivot does not say which field a term is in. Keep them in one field.");
            }

            $taxonomies[$field->of] = $name;
        }
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
