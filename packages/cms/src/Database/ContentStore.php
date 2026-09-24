<?php

namespace Mainstay\Database;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Content\ContentType;
use Mainstay\Content\Entry;
use Mainstay\Fields\Field;
use Mainstay\Mainstay;
use Mainstay\Policies\EntryPolicy;
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

    /* What Str::slug() writes, and the only thing a routed field holds. */
    private const SLUG = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /*
     | How often a write runs before a deadlock is the caller's. MySQL takes a
     | gap lock for deleting paths an entry does not have yet, so two saves
     | whose paths never meet can each hold one and wait on the other's
     | insert; the one the server picks rolls back and runs again. Laravel
     | retries only a transaction of its own, so inside a seeder's the
     | deadlock reaches the seeder.
     */
    private const ATTEMPTS = 3;

    public function __construct(private Mainstay $mainstay) {}

    /**
     * @template T of Entry
     *
     * @param  class-string<T>  $type
     * @return Collection<int, T>
     */
    public function find(string $type, array $where = [], string|array $sort = [], ?int $limit = null, ?string $locale = null, bool $overrideAccess = false): Collection
    {
        /* Nothing, on three drivers; everything, on SQL Server, whose grammar
           writes no `top` for it. Refused on all four instead. */
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException("A limit reads at least one entry; {$limit} is not one.");
        }

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
        if ($perPage < 1) {
            throw new InvalidArgumentException("A page holds at least one entry; {$perPage} is not one.");
        }

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

    /**
     * Writes belong here rather than to the admin, for the reason reads do:
     * a form, an HTTP call and a seeder save through the same rules, so none
     * of them can validate differently from the others.
     *
     * @template T of Entry
     *
     * @param  class-string<T>  $type
     * @return T
     */
    public function create(string $type, array $data, ?string $locale = null, bool $overrideAccess = false): Entry
    {
        $type = $this->entry($type);
        $locale = $this->locale($locale);

        if (! $overrideAccess) {
            $this->gate($type)->authorize('create', $type);
        }

        $id = $this->write($type, null, $this->validate($type, $data, []), $data, $locale, false);

        return $this->readBack($type, $id, $locale, $overrideAccess);
    }

    /**
     * Only the keys given change; the rest keep what is stored.
     *
     * Without a locale, this updates the entry as the request's locale reads
     * it, and an entry with no row there is not found -- what find() would
     * say. The request's language never creates content. With a locale
     * written out that the entry has no row in yet, this adds that
     * translation, and its required localized fields are then required of
     * the call, since there is nothing stored for them.
     *
     * @template T of Entry
     *
     * @param  class-string<T>  $type
     * @return T
     */
    public function update(string $type, int $id, array $data, ?string $locale = null, bool $overrideAccess = false): Entry
    {
        $type = $this->entry($type);
        $asked = $locale !== null;
        $locale = $this->locale($locale);
        [$row, $translations] = $this->load($type, $id);

        if (! $asked && ! $translations->has($locale)) {
            throw new RecordNotFoundException("{$type} {$id} has no {$locale} translation to update. Pass locale: '{$locale}' to add one.");
        }

        if (! $overrideAccess) {
            $this->gate($type)->authorize('update', $this->hydrate($type, $row, null, true));
        }

        $this->write($type, $id, $this->validate($type, $data, $this->stored($type, $row, $translations->get($locale))), $data, $locale, $translations->has($locale));

        return $this->readBack($type, $id, $locale, $overrideAccess);
    }

    /*
     | Into the trash, every locale at once, and out of the lookup in the same
     | request: a path left behind would resolve to a row the read scope then
     | hides.
     */
    public function delete(string $type, int $id, bool $overrideAccess = false): void
    {
        $type = $this->entry($type);
        [$row] = $this->load($type, $id);

        if (! $overrideAccess) {
            $this->gate($type)->authorize('delete', $this->hydrate($type, $row, null, true));
        }

        DB::transaction(function () use ($type, $id) {
            $now = $this->stamp(CarbonImmutable::now());

            DB::table($type::handle())->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);

            /* By key, for the reason paths() writes that way. */
            $paths = DB::table('uris')->where('type', $type::handle())->where('entry_id', $id)->pluck('id');

            if ($paths->isNotEmpty()) {
                DB::table('uris')->whereIn('id', $paths)->delete();
            }
        }, self::ATTEMPTS);
    }

    /* What a write committed, as the caller may read it. Gone only if a
       delete landed after the commit, which is what the caller is then
       told. */
    private function readBack(string $type, int $id, string $locale, bool $overrideAccess): Entry
    {
        return $this->findById($type, $id, $locale, $overrideAccess)
            ?? throw new RecordNotFoundException("{$type} {$id} was written and is gone from {$locale}.");
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

    /* Whether the caller sees internal fields, after asking whether it may
       read at all. */
    private function reads(string $type, bool $overrideAccess): bool
    {
        if ($overrideAccess) {
            return true;
        }

        $gate = $this->gate($type);
        $gate->authorize('viewAny', $type);

        return $gate->allows('viewInternal', $type);
    }

    /*
     | Gate, asked about Mainstay's own user and never the default guard's: on
     | a host with members of its own, that would be a site visitor answering
     | Mainstay's policies.
     |
     | EntryPolicy answers for the type unless the host chose another, the
     | way Laravel reads a choice: Gate::policy() on the type, #[UsePolicy]
     | on it, or Gate::policy() on a class or interface it extends. Chosen,
     | not guessed: Laravel matches App\Policies\PostPolicy to any class called
     | Post before it looks at what a class extends, and a host's Eloquent
     | Post and a content type called Post are different things -- the host's
     | policy, typed for its own users, would refuse every public read.
     |
     | So the answer is pinned to the exact type, on the copy forUser() hands
     | back and not on the host's Gate. Resolved on every call, a policy the
     | host registers later is honoured, and what the host's own Gate says
     | about the type is left as Laravel would say it.
     */
    private function gate(string $type): GateContract
    {
        $gate = Gate::forUser($this->user());
        $policies = $gate->policies();

        if (array_key_exists($type, $policies) || (new ReflectionClass($type))->getAttributes(UsePolicy::class) !== []) {
            return $gate;
        }

        /* Laravel's own fallback, in its order: the first registered that
           the type extends or implements. */
        foreach ($policies as $expected => $policy) {
            if (is_subclass_of($type, $expected)) {
                return $gate->policy($type, $policy);
            }
        }

        return $gate->policy($type, EntryPolicy::class);
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

        /* Refused on every driver because SQL Server refuses it: a column
           named twice in one ORDER BY is an error there and a second mention
           the others ignore. */
        $sorted = array_map(fn (string $key) => ltrim($key, '-'), (array) $sort);

        if (count($sorted) !== count(array_unique($sorted))) {
            throw new InvalidArgumentException('The sort names '.implode(', ', array_unique(array_diff_assoc($sorted, array_unique($sorted)))).' more than once.');
        }

        foreach ((array) $sort as $key) {
            $this->sort($query, $type, $key, $internal);
        }

        /* Last, so rows that tie on everything asked for keep one order and a
           page never repeats a row the page before it showed -- unless the
           sort already names it. */
        if (in_array('id', $sorted, true)) {
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
                ...array_map(fn (string $column) => "{$handle}.{$column}", ['id', 'owner_id', 'created_at', 'updated_at', ...array_keys($shared)]),
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

            /* != and not_in answer as SQL does: a row holding null matches
               neither, since null is not a value to be unequal to. */
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

    /*
     | The main row and every locale's row, by locale. Out of the trash and on
     | this site, or RecordNotFoundException, which Laravel answers with a 404.
     |
     | @return array{0: object, 1: Collection<string, object>}
     */
    private function load(string $type, int $id): array
    {
        $handle = $type::handle();

        $row = DB::table($handle)
            ->where('site_id', $this->site())
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->firstOrFail();

        return [$row, DB::table("{$handle}_locales")->where('parent_id', $id)->get()->keyBy('locale')];
    }

    /*
     | What is stored, by property, as the columns hold it. Not through cast():
     | a translation not written yet has no row, and cast() refuses the null
     | a required select or date would read as. Its localized keys are left
     | out instead, which the validator then reports as missing.
     */
    private function stored(string $type, object $row, ?object $translation): array
    {
        $stored = [];

        foreach ($this->mainstay->fields($type) as $name => $field) {
            $source = $field->localized ? $translation : $row;

            if ($source !== null) {
                $stored[$name] = $source->{Str::snake($name)};
            }
        }

        return $stored;
    }

    /*
     | The entry as it will be stored -- what is there, with what was given
     | over it -- checked against the declared rules. Every stored value is
     | checked again, not only the ones given: a row that went in some other
     | way and breaks a rule refuses the next save, naming the field.
     */
    private function validate(string $type, array $data, array $stored): array
    {
        $fields = $this->mainstay->fields($type);

        if (($unknown = array_diff_key($data, $fields)) !== []) {
            throw new InvalidArgumentException("{$type} has no field called ".implode(' or ', array_keys($unknown)).' to write.');
        }

        $values = [...$stored, ...$data];
        $routed = $this->placeholders($type);
        $rules = $messages = [];

        foreach ($fields as $name => $field) {
            $rules[$name] = $field->rules();
        }

        foreach ($routed as $name) {
            $rules[$name][] = 'regex:'.self::SLUG;
            $messages["{$name}.regex"] = 'The :attribute field is part of a path: lowercase letters, digits and single hyphens, the way Str::slug() writes one.';
        }

        Validator::make($values, $rules, $messages)->validate();

        return $values;
    }

    /*
     | The row, the locale's row and every locale's path, or none of them.
     |
     | A row inserted -- a create, or a locale's first -- gets every column
     | from the validated values, so a field left off it holds what its type
     | stores for nothing rather than whatever the column defaults to. A row
     | updated gets only the columns the call was given. The rest were read to
     | be validated, not to be written: written back, they would put a field
     | another save changed in the meantime back the way it was.
     */
    private function write(string $type, ?int $id, array $values, array $given, string $locale, bool $translated): int
    {
        $handle = $type::handle();
        $site = $this->site();
        [$localized, $shared] = $this->columns($type);
        $serialize = fn (array $fields) => array_map(fn (Field $field) => $field->serialize($values[$field->name] ?? null), $fields);
        $changed = fn (array $fields) => array_filter($fields, fn (Field $field) => array_key_exists($field->name, $given));

        return DB::transaction(function () use ($type, $handle, $id, $site, $locale, $translated, $localized, $shared, $serialize, $changed) {
            $created = $id === null;

            $now = $this->stamp(CarbonImmutable::now());

            if ($id === null) {
                $id = DB::table($handle)->insertGetId(['site_id' => $site, ...$serialize($shared), 'created_at' => $now, 'updated_at' => $now]);
            } else {
                /* Only while it is out of the trash, and asked again after. A
                   delete committed since the load leaves the update nothing
                   to match, and writing on would give a trashed entry its
                   paths back. The update holds the row from here, so a delete
                   arriving later waits for this commit. Asked with a locking
                   read: inside a caller's own transaction MySQL answers a
                   plain one from the snapshot taken before the delete. */
                DB::table($handle)->where('id', $id)->whereNull('deleted_at')->update([...$serialize($changed($shared)), 'updated_at' => $now]);

                if (DB::table($handle)->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->value('id') === null) {
                    throw new RecordNotFoundException("{$type} {$id} was deleted while it was being saved.");
                }
            }

            /* An update with no columns compiles to `set  where`, and an
               upsert with none quietly becomes an insert. A call that changed
               nothing localized has only the locale's row to add, if that. */
            if (! $translated) {
                DB::table("{$handle}_locales")->insert(['parent_id' => $id, 'site_id' => $site, 'locale' => $locale, ...$serialize($localized)]);
            } elseif (($columns = $changed($localized)) !== []) {
                DB::table("{$handle}_locales")->where('parent_id', $id)->where('locale', $locale)->update($serialize($columns));
            }

            $this->paths($type, (int) $id, $site, $created);

            return (int) $id;
        }, self::ATTEMPTS);
    }

    /*
     | Every locale's path, worked out again after every write rather than
     | only for the locale written: a shared field in the pattern moves them
     | all. Only what changed is written, each row by its key. A path left as
     | it was is not touched, and none is deleted to be put back: on MySQL
     | deleting by entry takes a gap lock on the lookup, and two saves holding
     | one each deadlock on the other's insert. A new entry has nothing to
     | compare with, so it only inserts.
     |
     | A path another entry holds is refused on the fields that build it,
     | never suffixed -- an editor looking at a URL they did not choose is the
     | outcome the route decision rules out. The unique index is what decides,
     | rather than a look beforehand, so two saves racing for one path cannot
     | both win. The violation is rethrown at once: Postgres has abandoned the
     | transaction, and the next statement in it would fail for that instead.
     */
    private function paths(string $type, int $id, int $site, bool $created): void
    {
        $handle = $type::handle();
        $wanted = $this->wanted($type, $id);
        $held = $created
            ? collect()
            : DB::table('uris')->where('type', $handle)->where('entry_id', $id)->get(['id', 'locale', 'uri'])->keyBy('locale');

        if (($gone = $held->diffKeys($wanted)->pluck('id'))->isNotEmpty()) {
            DB::table('uris')->whereIn('id', $gone)->delete();
        }

        foreach ($wanted as $locale => $uri) {
            $row = $held->get($locale);

            if ($row?->uri === $uri) {
                continue;
            }

            try {
                if ($row === null) {
                    DB::table('uris')->insert(['site_id' => $site, 'locale' => $locale, 'uri' => $uri, 'type' => $handle, 'entry_id' => $id]);
                } else {
                    DB::table('uris')->where('id', $row->id)->update(['uri' => $uri]);
                }
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(array_fill_keys($this->blamed($type), "The path {$uri} is already taken in {$locale}."));
            }
        }
    }

    /*
     | The path each of the entry's locale rows answers to, by locale. None
     | for a type with no route, or for a row in a locale that has since left
     | the config and so has no pattern to be given one by.
     |
     | @return array<string, string>
     */
    private function wanted(string $type, int $id): array
    {
        if (($patterns = $this->patterns($type)) === null) {
            return [];
        }

        $handle = $type::handle();
        $row = DB::table($handle)->where('id', $id)->first();
        $fields = $this->mainstay->fields($type);
        $wanted = [];

        foreach (DB::table("{$handle}_locales")->where('parent_id', $id)->get() as $translation) {
            if (! isset($patterns[$translation->locale])) {
                continue;
            }

            $uri = preg_replace_callback(
                '/\{(\w+)\}/',
                fn (array $name) => ($fields[$name[1]]->localized ? $translation : $row)->{Str::snake($name[1])},
                $patterns[$translation->locale],
            );

            /* The column's width, which the drivers other than SQLite enforce
               with an error nobody reading a form could act on. */
            if (mb_strlen($uri) > 255) {
                throw ValidationException::withMessages(array_fill_keys($this->blamed($type), "The path {$uri} is longer than the 255 characters a path can be."));
            }

            $wanted[$translation->locale] = $uri;
        }

        return $wanted;
    }

    /* The fields a refused path is reported on: the ones that build it, or
       the path itself for a route with none. */
    private function blamed(string $type): array
    {
        return $this->placeholders($type) ?: ['uri'];
    }

    /*
     | The type's pattern for each content locale, or null for a type with no
     | route. A map has to name exactly the configured locales: `nl-NL`
     | against `nl`, or a locale added to the config and not to the map, is a
     | path some translation would silently not get.
     |
     | @return array<string, string>|null
     */
    private function patterns(string $type): ?array
    {
        $route = $this->mainstay->route($type);
        $locales = config('mainstay.locales');

        if (! is_array($route)) {
            return $route === null ? null : array_fill_keys($locales, $route);
        }

        if (array_diff(array_keys($route), $locales) !== [] || array_diff($locales, array_keys($route)) !== []) {
            throw new InvalidArgumentException(sprintf(
                "%s's #[Route] has patterns for %s, and mainstay.locales holds %s. Give a pattern for every content locale, or one pattern for all of them.",
                $type,
                implode(', ', array_keys($route)),
                implode(', ', $locales),
            ));
        }

        return $route;
    }

    /* The fields a path is built from, in any locale's pattern. */
    private function placeholders(string $type): array
    {
        preg_match_all('/\{(\w+)\}/', implode(' ', $this->patterns($type) ?? []), $names);

        return array_values(array_unique($names[1]));
    }

    /*
     | A row as the declared class. `$locale` is null for the entry a write
     | asks Gate about, which is the main row alone -- its owner and its
     | shared fields, what a policy decides on -- so its locale, its path and
     | its localized fields are not set.
     */
    private function hydrate(string $type, object $row, ?string $locale, bool $internal): Entry
    {
        $entry = (new ReflectionClass($type))->newInstanceWithoutConstructor();

        $entry->id = (int) $row->id;
        $entry->ownerId = $row->owner_id === null ? null : (int) $row->owner_id;
        $entry->createdAt = $row->created_at === null ? null : CarbonImmutable::parse($row->created_at, 'UTC');
        $entry->updatedAt = $row->updated_at === null ? null : CarbonImmutable::parse($row->updated_at, 'UTC');

        if ($locale !== null) {
            $entry->locale = $locale;
            $entry->uri = $row->uri;
        }

        foreach ($this->mainstay->fields($type) as $name => $field) {
            if (! property_exists($row, Str::snake($name))) {
                continue;
            }

            $property = new ReflectionProperty($entry, $name);

            /* Absent rather than null. Null is a value a field holds, and a
               template printing a note it was not given should fail where it
               reads it rather than print nothing. A default the declaration
               gave goes for the same reason, unset from the declaring class,
               where a `protected(set)` property allows it; a readonly one has
               no default to take away. */
            if ($field->internal && ! $internal) {
                if ($property->isInitialized($entry)) {
                    Closure::bind(function () use ($name) {
                        unset($this->{$name});
                    }, $entry, $property->getDeclaringClass()->getName())();
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
