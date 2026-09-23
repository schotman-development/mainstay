<?php

namespace Mainstay\Database;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mainstay\Content\ContentType;
use Mainstay\Content\Entry;
use Mainstay\Fields\Field;
use Mainstay\Mainstay;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/*
 | The query layer: every read and write of content, for templates, seeders,
 | the admin's form and the HTTP API alike. Payload's Local API -- the call its
 | REST endpoint takes, run against the database with no HTTP hop -- so one
 | place decides what an entry looks like as data, and no consumer is handed a
 | different shape or a weaker check than another.
 |
 | The query builder rather than Eloquent. The declared class is the model: an
 | Eloquent one would hold the row in an attribute bag, a second object beside
 | the typed properties a template reads. The site and the trash are scoped in
 | query(), which every read starts from, so no read can be written without
 | them.
 */
class ContentStore
{
    /* Data rather than closures, so an HTTP query string can carry the same
       condition a template passes. */
    private const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'in', 'not_in'];

    /* The columns every main table has beside its fields, by the name a
       caller filters and sorts on. */
    private const STAMPS = ['id' => 'id', 'createdAt' => 'created_at', 'updatedAt' => 'updated_at'];

    public function __construct(private Mainstay $mainstay) {}

    /**
     * @template T of Entry
     *
     * @param  class-string<T>  $type
     * @return Collection<int, T>
     */
    public function find(string $type, array $where = [], string|array $sort = [], ?int $limit = null, ?string $locale = null, bool $overrideAccess = false): Collection
    {
        $type = $this->entry($type);
        $locale = $this->locale($locale);
        $internal = $this->reads($type, $overrideAccess);

        return $this->select($type, $locale, $where, $sort, $internal)
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => $this->hydrate($type, $row, $locale, $internal));
    }

    /**
     * @template T of Entry
     *
     * @param  class-string<T>  $type
     * @return LengthAwarePaginator<int, T>
     */
    public function paginate(string $type, array $where = [], string|array $sort = [], int $perPage = 15, ?int $page = null, ?string $locale = null, bool $overrideAccess = false): LengthAwarePaginator
    {
        $type = $this->entry($type);
        $locale = $this->locale($locale);
        $internal = $this->reads($type, $overrideAccess);

        return $this->select($type, $locale, $where, $sort, $internal)
            ->paginate($perPage, page: $page)
            ->through(fn (object $row) => $this->hydrate($type, $row, $locale, $internal));
    }

    /**
     * @template T of Entry
     *
     * @param  class-string<T>  $type
     * @return T|null
     */
    public function findById(string $type, int $id, ?string $locale = null, bool $overrideAccess = false): ?Entry
    {
        return $this->find($type, where: ['id' => $id], locale: $locale, overrideAccess: $overrideAccess)->first();
    }

    /*
     | The class as registered, which is the spelling every other lookup keys
     | on. Globals and taxonomies are refused rather than read as entries:
     | one row per site and a term's reverse query are rules this does not
     | know yet, and reading them as entries would write rows that break them.
     */
    private function entry(string $type): string
    {
        $registered = is_subclass_of($type, ContentType::class)
            ? $this->mainstay->registered()[$type::handle()] ?? null
            : null;

        if ($registered === null || strcasecmp(ltrim($type, '\\'), $registered) !== 0) {
            throw new InvalidArgumentException("{$type} is not a registered content type. Register it with Mainstay::types().");
        }

        if (! is_subclass_of($registered, Entry::class)) {
            throw new InvalidArgumentException("{$registered} is not an entry. The query layer reads and writes entries only; globals and taxonomies are not reachable through it yet.");
        }

        return $registered;
    }

    /*
     | The request's locale unless one is passed, so a host's locale
     | middleware -- or the catch-all -- picks the language a template reads
     | in. Refused rather than read when it is not a content locale, naming
     | where it came from: a visitor-locale middleware setting `de` otherwise
     | reads as every template on the site throwing for no reason.
     */
    private function locale(?string $locale): string
    {
        $locales = config('mainstay.locales');

        if (in_array($locale ?? App::getLocale(), $locales, true)) {
            return $locale ?? App::getLocale();
        }

        throw new InvalidArgumentException(sprintf(
            '%s, which is not a content locale: mainstay.locales holds %s.',
            $locale === null ? 'No locale was passed and App::getLocale() is "'.App::getLocale().'"' : "The locale is \"{$locale}\"",
            implode(', ', $locales),
        ));
    }

    /*
     | Whether the caller sees internal fields, after asking whether it may
     | read at all. Asked about Mainstay's own user and never the default
     | guard's: on a host with members of its own, that would be a site
     | visitor answering Mainstay's policies.
     */
    private function reads(string $type, bool $overrideAccess): bool
    {
        if ($overrideAccess) {
            return true;
        }

        $gate = Gate::forUser($this->user());
        $gate->authorize('viewAny', $type);

        return $gate->allows('viewInternal', $type);
    }

    /* Nobody until phase 9, which hands over the guard's user here and
       changes nothing else in this class. */
    private function user(): ?object
    {
        return null;
    }

    private function select(string $type, string $locale, array $where, string|array $sort, bool $internal): Builder
    {
        $query = $this->query($type, $locale);

        foreach ($where as $key => $condition) {
            $this->where($query, $type, (string) $key, $condition, $internal);
        }

        foreach ((array) $sort as $key) {
            $this->sort($query, $type, $key, $internal);
        }

        /* Last, so rows that tie on everything asked for keep one order and a
           page never repeats a row the page before it showed. Not when the
           sort already names it: SQL Server refuses a column twice in one
           ORDER BY. */
        if (in_array('id', array_map(fn (string $key) => ltrim($key, '-'), (array) $sort), true)) {
            return $query;
        }

        return $query->orderBy($type::handle().'.id');
    }

    /*
     | Every read starts here. The row in one locale -- an inner join, so an
     | entry with no row in that locale is not there, which is what no
     | fallback means -- and the path it answers to there. Both joins match
     | one row at most, which is what keeps paginate's count honest.
     |
     | Only the main row's `deleted_at` is asked about. The sibling has one
     | too, for trashing a single translation, and nothing sets it yet.
     */
    private function query(string $type, string $locale): Builder
    {
        $handle = $type::handle();
        $locales = "{$handle}_locales";
        [$localized, $shared] = $this->columns($type);

        return DB::table($handle)
            ->join($locales, fn (JoinClause $join) => $join
                ->on("{$locales}.parent_id", '=', "{$handle}.id")
                ->where("{$locales}.locale", $locale))
            ->leftJoin('uris', fn (JoinClause $join) => $join
                ->on('uris.entry_id', '=', "{$handle}.id")
                ->on('uris.site_id', '=', "{$handle}.site_id")
                ->where('uris.type', $handle)
                ->where('uris.locale', $locale))
            ->where("{$handle}.site_id", $this->site())
            ->whereNull("{$handle}.deleted_at")
            ->select([
                ...array_map(fn (string $column) => "{$handle}.{$column}", ['id', 'created_at', 'updated_at', ...array_keys($shared)]),
                ...array_map(fn (string $column) => "{$locales}.{$column}", array_keys($localized)),
                'uris.uri',
            ]);
    }

    /*
     | The fields by column, localized ones and the rest, split the way
     | ContentSchema::tables() splits them onto the two tables.
     |
     | @return array{0: array<string, Field>, 1: array<string, Field>}
     */
    private function columns(string $type): array
    {
        return collect($this->mainstay->fields($type))
            ->keyBy(fn (Field $field) => Str::snake($field->name))
            ->partition(fn (Field $field) => $field->localized)
            ->map->all()
            ->all();
    }

    /* The first site, read on every call rather than kept: this outlives a
       request under Octane, and phase 12 matches the request's host here. */
    private function site(): int
    {
        return (int) (DB::table('sites')->orderBy('id')->value('id')
            ?? throw new RuntimeException('There is no site to hold content. Run php artisan migrate.'));
    }

    private function where(Builder $query, string $type, string $key, mixed $condition, bool $internal): void
    {
        [$column, $field] = $this->column($type, $key, $internal);

        /* A list reads as "any of these" and could as easily mean "all of
           them"; `in` says which. */
        if (is_array($condition) && array_is_list($condition)) {
            throw new InvalidArgumentException("The condition on {$key} is a list. For any one of several values, write ['in' => [...]].");
        }

        foreach (is_array($condition) ? $condition : ['=' => $condition] as $operator => $value) {
            if (! in_array($operator, self::OPERATORS, true)) {
                throw new InvalidArgumentException("{$operator} is not an operator a where takes. It takes ".implode(', ', self::OPERATORS).'.');
            }

            if ($operator === 'in' || $operator === 'not_in') {
                $query->whereIn($column, array_map(fn (mixed $one) => $this->value($field, $key, $one), (array) $value), not: $operator === 'not_in');

                continue;
            }

            $value = $this->value($field, $key, $value);

            match (true) {
                $value !== null => $query->where($column, $operator, $value),
                $operator === '=' => $query->whereNull($column),
                $operator === '!=' => $query->whereNotNull($column),
                default => throw new InvalidArgumentException("{$key} is compared with {$operator} against nothing. Only = and != take null."),
            };
        }
    }

    private function sort(Builder $query, string $type, string $key, bool $internal): void
    {
        $descending = str_starts_with($key, '-');
        $key = $descending ? substr($key, 1) : $key;
        [$column, $field] = $this->column($type, $key, $internal);

        /* Nulls last whichever way, on every driver. Left to the driver,
           Postgres puts them last ascending and first descending and the
           other three the opposite, so `-publishedAt` floats the undated
           entries to the top on one database only. */
        if ($field?->nullable ?? $key !== 'id') {
            $query->orderByRaw('case when '.$query->getGrammar()->wrap($column).' is null then 1 else 0 end');
        }

        $query->orderBy($column, $descending ? 'desc' : 'asc');
    }

    /*
     | The qualified column a caller's key is stored in, and the field that
     | stores it -- null for a stamp.
     |
     | An internal field the caller cannot see is refused in the words an
     | unknown one is. A refusal of its own would confirm the field exists,
     | and a filter on it would read its value back off which entries match.
     |
     | @return array{0: string, 1: ?Field}
     */
    private function column(string $type, string $key, bool $internal): array
    {
        $handle = $type::handle();

        if (isset(self::STAMPS[$key])) {
            return ["{$handle}.".self::STAMPS[$key], null];
        }

        $field = $this->mainstay->fields($type)[$key] ?? null;

        if ($field === null || ($field->internal && ! $internal)) {
            throw new InvalidArgumentException("{$type} has no field called {$key} to filter or sort on.");
        }

        return [($field->localized ? "{$handle}_locales" : $handle).'.'.Str::snake($key), $field];
    }

    /* The value as the column holds it, so the database compares like with
       like: a date in another zone becomes the UTC string stored, rather than
       being formatted in its own zone and compared off by the offset. */
    private function value(?Field $field, string $key, mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            $field !== null => $field->serialize($value),
            $key === 'id' => (int) $value,
            default => $this->stamp($value),
        };
    }

    /* A moment as the stamp columns hold one: UTC, as Date(time: true) writes
       it and for the same reason. */
    private function stamp(mixed $value): string
    {
        $moment = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC');

        return $moment->utc()->format('Y-m-d H:i:s');
    }

    private function hydrate(string $type, object $row, string $locale, bool $internal): Entry
    {
        $entry = (new ReflectionClass($type))->newInstanceWithoutConstructor();

        $entry->id = (int) $row->id;
        $entry->locale = $locale;
        $entry->createdAt = $row->created_at === null ? null : CarbonImmutable::parse($row->created_at, 'UTC');
        $entry->updatedAt = $row->updated_at === null ? null : CarbonImmutable::parse($row->updated_at, 'UTC');
        $entry->uri = $row->uri;

        foreach ($this->mainstay->fields($type) as $name => $field) {
            $property = new ReflectionProperty($entry, $name);

            /* Absent rather than null. Null is a value a field holds, and a
               template printing a note it was not given should fail where it
               reads it rather than print nothing. A default the declaration
               gave goes for the same reason; a readonly property has none. */
            if ($field->internal && ! $internal) {
                if ($property->isInitialized($entry)) {
                    unset($entry->{$name});
                }

                continue;
            }

            /* Through reflection, which initializes a readonly property from
               outside its class where assignment cannot. */
            $property->setValue($entry, $field->cast($row->{Str::snake($name)}));
        }

        return $entry;
    }
}
