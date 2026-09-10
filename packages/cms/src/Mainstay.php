<?php

namespace Mainstay;

use InvalidArgumentException;
use Mainstay\Content\ContentType;
use Mainstay\Fields\Field;
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

        /*
         | Base classes first. getProperties() answers with a class's own
         | properties before the ones it inherits, which would file a shared
         | base's title after everything the child adds -- an order the
         | declaration does not show anywhere, on a list phase 5 draws the form
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
