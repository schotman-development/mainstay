<?php

namespace Mainstay\Database;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Support\Arrayable;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Mainstay\Content\ContentType;
use Mainstay\Content\Draft;
use Mainstay\Content\Entry;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\Media;
use Mainstay\Content\Revision;
use Mainstay\Content\Route;
use Mainstay\Content\Taxonomy;
use Mainstay\Fields\Date;
use Mainstay\Fields\Field;
use Mainstay\Fields\Relation;
use Mainstay\Fields\Terms;
use Mainstay\Fields\Text;
use Mainstay\Fields\Textarea;
use Mainstay\Mainstay;
use Mainstay\Policies\EntryPolicy;
use Mainstay\Policies\GlobalPolicy;
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

    /*
     | How often a write runs before a deadlock is the caller's. Two saves
     | inserting the same path on MySQL each lock it for the duplicate check
     | and wait on the other's insert; the one the server picks rolls back,
     | runs again, and is then told the path is taken. Laravel retries only a
     | transaction of its own, so inside a seeder's the deadlock reaches the
     | seeder.
     */
    private const ATTEMPTS = 3;

    /* How far a read follows relations when the call does not say: to the
       entries they point at, whose own relations it leaves unloaded. */
    private const DEPTH = 1;

    private const DRAFTS = 'mainstay_drafts';

    private const REVISIONS = 'mainstay_revisions';

    /** @var array<class-string, array{0: ReflectionClass, 1: array<string, ReflectionProperty>}> */
    private array $reflected = [];

    private ?int $site = null;

    /** @var array<string, bool> by type, whether the caller sees its internal fields */
    private array $sees = [];

    /* The stamp columns, written and read as Date(time: true) writes and
       reads a moment: in UTC. Unbound, which serialize() and cast() only
       notice for a blank value, and none reaches it: a write hands it now,
       and a read and value() take a blank as null first. */
    private Date $moment;

    public function __construct(private Mainstay $mainstay)
    {
        $this->moment = new Date(time: true);
    }

    /**
     * @template T of Entry
     *
     * @param  class-string<T>  $type
     * @return Collection<int, T>
     */
    public function find(string $type, array $where = [], string|array $sort = [], ?int $limit = null, ?string $locale = null, bool $overrideAccess = false, int $depth = self::DEPTH): Collection
    {
        /* Nothing, on three drivers; everything, on SQL Server, whose grammar
           writes no `top` for it. Refused on all four instead. */
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException("A limit reads at least one entry; {$limit} is not one.");
        }

        $type = $this->entry($type);
        $locale = $this->locale($locale);
        $this->depth($depth);
        $internal = $this->reads($type, $overrideAccess);

        $rows = $this->select($type, $locale, $where, $sort, $internal)->limit($limit)->get();
        $this->attach($type, $rows);
        $loaded = $this->resolve([$type => $rows], $locale, $overrideAccess, $depth);

        return $rows->map(fn (object $row) => $this->hydrate($type, $row, $locale, $internal, loaded: $loaded));
    }

    /**
     * @template T of Entry
     *
     * @param  class-string<T>  $type
     * @return LengthAwarePaginator<int, T>
     */
    public function paginate(string $type, array $where = [], string|array $sort = [], int $perPage = 15, ?int $page = null, ?string $locale = null, bool $overrideAccess = false, int $depth = self::DEPTH): LengthAwarePaginator
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException("A page holds at least one entry; {$perPage} is not one.");
        }

        $type = $this->entry($type);
        $locale = $this->locale($locale);
        $this->depth($depth);
        $internal = $this->reads($type, $overrideAccess);

        $entries = $this->select($type, $locale, $where, $sort, $internal)->paginate($perPage, page: $page);
        $this->attach($type, $entries->items());
        $loaded = $this->resolve([$type => $entries->items()], $locale, $overrideAccess, $depth);

        return $entries->through(fn (object $row) => $this->hydrate($type, $row, $locale, $internal, loaded: $loaded));
    }

    /**
     * @template T of Entry
     *
     * @param  class-string<T>  $type
     * @return T|null
     */
    public function findById(string $type, int $id, ?string $locale = null, bool $overrideAccess = false, int $depth = self::DEPTH): ?Entry
    {
        return $this->find($type, where: ['id' => $id], locale: $locale, overrideAccess: $overrideAccess, depth: $depth)->first();
    }

    /*
     | The entry a path leads to in a locale, whatever its type: the lookup
     | row, then the entry read as findById() reads it.
     |
     | Null, rather than refused, for a type the caller may not read. The
     | caller named a path and not a type, and a refusal would tell it the
     | path is there. Null without a query for a path that is not lowercase
     | segments, which no path is stored as and which MySQL and SQL Server
     | would still match to one by folding case.
     */
    public function findByUri(string $uri, ?string $locale = null, bool $overrideAccess = false, int $depth = self::DEPTH): ?Entry
    {
        $locale = $this->locale($locale);

        if ($uri !== '/' && ! preg_match('#\A'.Route::PATH.'\z#', $uri)) {
            return null;
        }

        $row = DB::table('uris')->where('site_id', $this->site())->where('locale', $locale)->where('uri', $uri)->first(['type', 'entry_id']);
        $type = $row === null ? null : $this->mainstay->registered()[$row->type] ?? null;

        if ($type === null || ! is_subclass_of($type, Entry::class) || ! ($overrideAccess || $this->gate($type)->allows('viewAny', $type))) {
            return null;
        }

        return $this->findById($type, (int) $row->entry_id, $locale, $overrideAccess, $depth);
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

        [$internal, $reads] = $this->writes($type, 'create', $type, $overrideAccess);

        $id = $this->write($type, null, $this->validate($type, $data, [], $internal, $overrideAccess), $data, $locale, false, $reads);

        return $this->readBack($type, $id, $locale, $internal, $reads ? null : array_keys($data), $overrideAccess);
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
        /* Twice at most. A translation this save found missing, and another
           added before this one's insert, is refused by the (parent_id,
           locale) index; the second run finds it there and updates it, as
           the save would have had it come a moment later. The second run
           reads the translations with a lock where the first read may have
           come from a caller's snapshot, which would miss it again. */
        for ($attempt = 1; ; $attempt++) {
            [$row, $translations] = $this->load($type, $id, $overrideAccess, $attempt > 1 && $this->snapshotted(0));

            if (! $asked && ! $translations->has($locale)) {
                throw $this->missing($type, $overrideAccess, "{$type} {$id} has no {$locale} translation to update. Pass locale: '{$locale}' to add one.");
            }

            [$internal, $reads] = $this->writes($type, 'update', fn () => $this->hydrate($type, $row, null, true), $overrideAccess);

            try {
                $values = $this->validate($type, $data, $this->stored($type, $row, $translations->get($locale), $internal), $internal, $overrideAccess);
                $this->filed($type, $id, fn () => $this->write($type, $id, $values, $data, $locale, $translations->has($locale), $reads));

                break;
            } catch (UniqueConstraintViolationException $exception) {
                if ($translations->has($locale) || $attempt > 1) {
                    throw $exception;
                }
            }
        }

        return $this->readBack($type, $id, $locale, $internal, $reads ? null : array_keys($data), $overrideAccess);
    }

    /*
     | Into the trash, every locale at once, and out of the lookup in the same
     | request: a path left behind would resolve to a row the read scope then
     | hides.
     */
    public function delete(string $type, int $id, bool $overrideAccess = false): void
    {
        $type = $this->entry($type);
        [$row] = $this->load($type, $id, $overrideAccess);

        $this->writes($type, 'delete', fn () => $this->hydrate($type, $row, null, true), $overrideAccess);

        DB::transaction(function () use ($type, $id) {
            $now = $this->stamp(CarbonImmutable::now());

            DB::table($type::handle())->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);

            /* By entry, which reads the rows as they are now: MySQL answers a
               plain read inside a caller's transaction from its snapshot, and
               a path added since would outlive the trash. The gap lock this
               takes makes a create inserting beside it wait until the commit.
               Alone that is a wait, not a deadlock; a caller's own
               transaction that writes a path after the delete can turn it
               into one, which reaches the caller. */
            DB::table('uris')->where('type', $type::handle())->where('entry_id', $id)->delete();
        }, self::ATTEMPTS);
    }

    /*
     | This site's global in a locale, or null where it has not been written,
     | or not in that locale: absent, as an untranslated entry is, rather than
     | a global of defaults nobody stored.
     */
    public function global(string $type, ?string $locale = null, bool $overrideAccess = false, int $depth = self::DEPTH): ?GlobalSet
    {
        $type = $this->globalSet($type);
        $locale = $this->locale($locale);
        $this->depth($depth);
        $internal = $this->reads($type, $overrideAccess);

        if (($row = $this->query($type, $locale)->first()) === null) {
            return null;
        }

        return $this->hydrate($type, $row, $locale, $internal, loaded: $this->resolve([$type => [$row]], $locale, $overrideAccess, $depth));
    }

    /*
     | Writes this site's global: its row the first time, and a locale's row
     | the first time that locale is written, as update() adds a translation.
     | Only the keys given change. Without a locale it saves the global as the
     | request's locale reads it, and is refused where that has no row: the
     | request's language never creates content.
     |
     | Gate is asked for `update` about the type, the row there or not: a
     | global is never created or deleted as far as anyone asking is
     | concerned.
     |
     | Two first saves racing are settled by the unique index on site_id. The
     | one refused runs again, finds the row the other wrote and updates it;
     | its insert was in a transaction of the write's own, a savepoint inside
     | a caller's, so a caller's Postgres transaction goes on. The rows are
     | read again with a lock where a plain read would answer from the
     | caller's older snapshot and miss them.
     */
    public function saveGlobal(string $type, array $data, ?string $locale = null, bool $overrideAccess = false): GlobalSet
    {
        $type = $this->globalSet($type);
        $handle = $type::handle();
        $asked = $locale !== null;
        $locale = $this->locale($locale);

        [$internal, $reads] = $this->writes($type, 'update', $type, $overrideAccess);

        for ($attempt = 1; ; $attempt++) {
            $lock = $attempt > 1 && $this->snapshotted(0);
            $row = DB::table($handle)->where('site_id', $this->site())->when($lock, fn (Builder $query) => $query->lockForUpdate())->first();
            $translations = $row === null ? collect() : DB::table("{$handle}_locales")->where('parent_id', $row->id)
                ->when($lock, fn (Builder $query) => $query->lockForUpdate())->get()->keyBy('locale');

            if (! $asked && ! $translations->has($locale)) {
                throw $this->missing($type, $overrideAccess, "{$type} has no {$locale} translation to save. Pass locale: '{$locale}' to add one.");
            }

            try {
                $stored = $row === null ? [] : $this->stored($type, $row, $translations->get($locale), $internal);
                $values = $this->validate($type, $data, $stored, $internal, $overrideAccess);
                $held = $row === null ? null : (int) $row->id;
                $id = $this->filed($type, $held, fn () => $this->write($type, $held, $values, $data, $locale, $translations->has($locale), $reads));

                break;
            } catch (UniqueConstraintViolationException $exception) {
                if (($row !== null && $translations->has($locale)) || $attempt > 1) {
                    throw $exception;
                }
            }
        }

        return $this->readBack($type, $id, $locale, $internal, $reads ? null : array_keys($data), $overrideAccess);
    }

    /*
     | Merges the keys given into a draft: an entry's, `$entry`; one already
     | started, by its own id, `$draft`; a global's, this site's one; or, with
     | neither, a new entry's, which has no row, id or path until it is
     | published. Each key is compared with what is live in the locale and
     | kept only where it differs, so a form posting every field leaves a
     | draft of what was changed, and a draft left changing nothing is
     | deleted -- null is then handed back.
     |
     | A draft may be incomplete: what it holds is checked as a write checks
     | it, but a field's own presence rule gives way. The locale is taken as
     | update() takes it, so without one a translation neither live nor
     | drafted is refused, and with one it is added; a new entry's is taken
     | as create() takes it.
     |
     | One draft per entry is held by the drafts' unique index: two first
     | saves racing are settled by it, in a transaction of the write's own,
     | and the one refused runs again and merges into the other's. The draft
     | merged into is read with a lock, so two saves merge rather than one
     | undoing the other.
     */
    public function saveDraft(string $type, array $data, ?int $entry = null, ?int $draft = null, ?string $locale = null, bool $overrideAccess = false): ?Draft
    {
        $type = $this->drafted($type);
        $global = is_subclass_of($type, GlobalSet::class);

        if ($draft !== null && $entry !== null) {
            throw new InvalidArgumentException('A draft is saved for an entry or by its own id, not both.');
        }

        if ($global && $entry !== null) {
            throw new InvalidArgumentException("{$type} is a global, whose draft is the site's one: save it without an entry.");
        }

        $held = null;

        if ($draft !== null) {
            $held = DB::table(self::DRAFTS)->where('site_id', $this->site())->where('type', $type::handle())->where('id', $draft)->first()
                ?? throw $this->missing($type, $overrideAccess, "Draft {$draft} of {$type} is not there to save.");
        }

        $entry = $global ? 0 : ($held === null ? $entry : ($held->entry_id === null ? null : (int) $held->entry_id));
        $held ??= $entry === null ? null : $this->draftFor($type, $entry);
        $asked = $locale !== null;
        $locale = $this->locale($locale);
        [$row, $translations] = $this->live($type, $entry, $overrideAccess);
        [$internal] = $this->drafter($type, $entry, $row, $overrideAccess);
        $drafted = $held === null ? [] : $this->changes($held)['locales'];

        if (! $asked && $entry !== null && ! $translations->has($locale) && ! isset($drafted[$locale])) {
            throw $this->missing($type, $overrideAccess, "{$type} has no {$locale} translation to draft. Pass locale: '{$locale}' to add one.");
        }

        $values = $this->validate($type, $data, $row === null ? [] : $this->stored($type, $row, $translations->get($locale), $internal), $internal, $overrideAccess, draft: true);
        $fields = $this->mainstay->fields($type);
        $changes = [];

        foreach (array_keys($data) as $name) {
            $view = ! $global && $name === 'template';
            $field = $view ? null : $fields[$name];
            $source = $field?->localized ? $translations->get($locale) : $row;
            $value = $view ? (blank($data[$name]) ? null : $data[$name]) : $this->kept($field, $values[$name] ?? null);
            $differs = $source === null || ($view ? $source->template : $this->normal($field, $source->{Str::snake($name)} ?? null)) !== $value;

            $changes[] = [$field?->localized ? $locale : null, $name, $value, $differs];
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $id = DB::transaction(function () use ($type, $entry, $draft, $held, $locale, $translations, $changes, $attempt) {
                    /* Again, the second time, with a lock: the first missed the
                       draft another save committed, which a plain read inside
                       a caller's MySQL transaction would miss again. */
                    $found = $draft ?? ($attempt === 1 ? $held?->id : $this->draftFor($type, $entry, $this->snapshotted(1))?->id);
                    $current = $found === null ? null : DB::table(self::DRAFTS)->where('id', $found)->lockForUpdate()->first();

                    if ($draft !== null && $current === null) {
                        throw new RecordNotFoundException("Draft {$draft} was published or discarded while it was being saved.");
                    }

                    $merged = $current === null ? ['fields' => [], 'locales' => []] : $this->changes($current);

                    foreach ($changes as [$at, $name, $value, $differs]) {
                        if ($at === null && $differs) {
                            $merged['fields'][$name] = $value;
                        } elseif ($at === null) {
                            unset($merged['fields'][$name]);
                        } elseif ($differs) {
                            $merged['locales'][$at][$name] = $value;
                        } else {
                            unset($merged['locales'][$at][$name]);
                        }
                    }

                    /* A translation the entry has is drafted only by what
                       changes in it; one it lacks is added by the draft,
                       changes or none. */
                    if (! $translations->has($locale)) {
                        $merged['locales'][$locale] ??= [];
                    } elseif (($merged['locales'][$locale] ?? null) === []) {
                        unset($merged['locales'][$locale]);
                    }

                    if ($merged['fields'] === [] && $merged['locales'] === []) {
                        if ($current !== null) {
                            DB::table(self::DRAFTS)->where('id', $current->id)->delete();
                        }

                        return null;
                    }

                    $now = $this->stamp(CarbonImmutable::now());
                    $encoded = json_encode($merged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if ($current !== null) {
                        DB::table(self::DRAFTS)->where('id', $current->id)->update(['changes' => $encoded, 'updated_at' => $now]);

                        return (int) $current->id;
                    }

                    return (int) DB::table(self::DRAFTS)->insertGetId(['site_id' => $this->site(), 'type' => $type::handle(), 'entry_id' => $entry, 'changes' => $encoded, 'created_at' => $now, 'updated_at' => $now]);
                }, self::ATTEMPTS);

                break;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt > 1 || $entry === null) {
                    throw $exception;
                }
            }
        }

        if ($id === null) {
            return null;
        }

        return $this->readDraft($type, DB::table(self::DRAFTS)->where('id', $id)->first(), $locale, $overrideAccess, self::DEPTH, $internal);
    }

    /* A draft by its own id, read in a locale: null where there is none, or
       none in that locale -- neither live nor added by the draft -- or where
       the entry it changes is in the trash. */
    public function findDraft(int $id, ?string $locale = null, bool $overrideAccess = false, int $depth = self::DEPTH): ?Draft
    {
        $row = DB::table(self::DRAFTS)->where('site_id', $this->site())->where('id', $id)->first();

        return $row === null ? null : $this->readDraft($this->drafted($this->handled($row->type)), $row, $locale, $overrideAccess, $depth);
    }

    /* The draft of an entry, or of a global without one. */
    public function draftOf(string $type, ?int $entry = null, ?string $locale = null, bool $overrideAccess = false, int $depth = self::DEPTH): ?Draft
    {
        $type = $this->drafted($type);

        if (is_subclass_of($type, GlobalSet::class) === ($entry !== null)) {
            throw new InvalidArgumentException($entry === null ? "{$type} is an entry: name the one whose draft to read." : "{$type} is a global, whose draft is the site's one: read it without an entry.");
        }

        /* Gate first, draft or none, so whether one is pending is no answer
           to a caller who may not read it. An entry in the trash has none to
           read, as findDraft() says. */
        try {
            [$live] = $this->live($type, $entry ?? 0, $overrideAccess);
        } catch (RecordNotFoundException) {
            return null;
        }

        [$internal] = $this->drafter($type, $entry ?? 0, $live, $overrideAccess);
        $row = $this->draftFor($type, $entry ?? 0);

        return $row === null ? null : $this->readDraft($type, $row, $locale, $overrideAccess, $depth, $internal);
    }

    public function discardDraft(int $id, bool $overrideAccess = false): void
    {
        $row = DB::table(self::DRAFTS)->where('site_id', $this->site())->where('id', $id)->first()
            ?? throw new RecordNotFoundException("Draft {$id} is not there to discard.");
        $type = $this->drafted($this->handled($row->type));
        $entry = $row->entry_id === null ? null : (int) $row->entry_id;

        [$live] = $this->live($type, $entry, $overrideAccess);
        $this->drafter($type, $entry, $live, $overrideAccess);

        DB::table(self::DRAFTS)->where('id', $id)->delete();
    }

    /*
     | Puts a draft live, in one transaction: the draft read with a lock, each
     | locale it names validated as an update of that locale is -- `required`
     | back, and the paths -- and written, the outgoing entry filed as a
     | revision, the draft deleted. A new entry's is a create in its first
     | locale and an update in each other, with nothing outgoing. A draft
     | changing only shared fields is written in the first locale the entry
     | has. Refused with nothing written as a save is.
     |
     | The one place "content changed" is known for what an editor wrote.
     | Nothing listens yet: how pages reach the public is still deferred, and
     | this is where it attaches.
     |
     | A draft changing an internal field is the work of someone who may see
     | it, and is refused in access terms, naming no field, to a publisher
     | who may not.
     */
    public function publish(int $id, bool $overrideAccess = false): ContentType
    {
        $draft = DB::table(self::DRAFTS)->where('site_id', $this->site())->where('id', $id)->first()
            ?? throw new RecordNotFoundException("Draft {$id} is not there to publish.");
        $type = $this->drafted($this->handled($draft->type));
        $global = is_subclass_of($type, GlobalSet::class);
        $entry = $draft->entry_id === null ? null : (int) $draft->entry_id;
        [$row] = $this->live($type, $entry, $overrideAccess);

        [$internal, $reads] = $this->writes($type, 'publish', $entry === null || $global ? $type : fn () => $this->hydrate($type, $row, null, true), $overrideAccess);

        $nested = $this->snapshotted(0);

        [$id, $locale, $given] = DB::transaction(function () use ($type, $id, $global, $internal, $reads, $overrideAccess, $nested) {
            $draft = DB::table(self::DRAFTS)->where('id', $id)->lockForUpdate()->first()
                ?? throw new RecordNotFoundException("Draft {$id} was published or discarded since.");
            $changes = $this->changes($draft);

            if (! $internal && $this->hidden($type, $changes) !== $changes) {
                throw new AuthorizationException('This draft changes what this caller may not see, so it cannot publish it.');
            }

            /* A global's row read again: a first save may have written it
               since. */
            $held = $global ? $this->live($type, 0, $overrideAccess)[0]?->id : $draft->entry_id;
            $held = $held === null ? null : (int) $held;
            $published = $this->filed($type, $held, fn () => $this->published($type, $held, $changes, $internal, $reads, $overrideAccess, $nested));

            DB::table(self::DRAFTS)->where('id', $id)->delete();

            return $published;
        }, self::ATTEMPTS);

        return $this->readBack($type, $id, $locale, $internal, $reads ? null : $given, $overrideAccess);
    }

    /*
     | A draft's locales written, through the write every save makes. The
     | shared fields go with the first. Hands back the entry's id, the locale
     | written first, and every key given.
     |
     | @return array{0: int, 1: string, 2: list<string>}
     */
    private function published(string $type, ?int $id, array $changes, bool $internal, bool $reads, bool $overrideAccess, bool $nested): array
    {
        /* In the configured order, so a new entry is created in the first of
           its languages the config names; one the config no longer names is
           refused when it comes up. */
        $configured = array_keys($this->mainstay->locales());
        $locales = [...array_intersect($configured, array_keys($changes['locales'])), ...array_diff(array_keys($changes['locales']), $configured)];

        if ($locales === []) {
            $has = DB::table("{$type::handle()}_locales")->where('parent_id', $id)->pluck('locale')->all();
            $locales = array_slice(array_values(array_intersect(array_keys($this->mainstay->locales()), $has)), 0, 1)
                ?: throw new RecordNotFoundException("{$type} {$id} has no translation in a content locale to publish its draft in.");
        }

        $given = [];

        foreach ($locales as $index => $locale) {
            $locale = $this->locale($locale);
            $data = [...($index === 0 ? $changes['fields'] : []), ...($changes['locales'][$locale] ?? [])];
            $given = [...$given, ...array_keys($data)];

            if ($id === null) {
                $id = $this->write($type, null, $this->validate($type, $data, [], $internal, $overrideAccess), $data, $locale, false, $reads);

                continue;
            }

            [$row, $translations] = $this->load($type, $id, $overrideAccess, $nested);
            $values = $this->validate($type, $data, $this->stored($type, $row, $translations->get($locale), $internal), $internal, $overrideAccess);
            $this->write($type, $id, $values, $data, $locale, $translations->has($locale), $reads);
        }

        return [$id, $this->locale($locales[0]), array_values(array_unique($given))];
    }

    /*
     | An entry's earlier versions, newest first, without their content: one
     | for every write that replaced something live, past the last
     | `mainstay.revisions` pruned. A global's without an entry.
     |
     | @return Collection<int, Revision>
     */
    public function revisionsOf(string $type, ?int $entry = null, bool $overrideAccess = false): Collection
    {
        $type = $this->drafted($type);
        $global = is_subclass_of($type, GlobalSet::class);

        if ($global === ($entry !== null)) {
            throw new InvalidArgumentException($global ? "{$type} is a global, whose revisions are the site's: list them without an entry." : "{$type} is an entry: name the one whose revisions to list.");
        }

        [$row] = $this->live($type, $entry ?? 0, $overrideAccess);
        $this->drafter($type, $entry ?? 0, $row, $overrideAccess);

        return DB::table(self::REVISIONS)->where('site_id', $this->site())->where('type', $type::handle())->where('entry_id', $entry ?? 0)
            ->orderByDesc('id')
            ->get(['id', 'created_at'])
            ->map(fn (object $revision) => new Revision((int) $revision->id, $entry, $this->moment->cast($revision->created_at)));
    }

    /*
     | A revision made the entry's draft: every field and locale it holds
     | given to saveDraft(), so the draft is what differs from live and
     | leaves through publish with nothing of its own. What the draft already
     | changes that the revision does not hold stays changed.
     |
     | What it cannot bring back is left as live has it and reported on the
     | Draft handed back: a field the type no longer declares, a locale no
     | longer configured, and a value its field now refuses -- retyped, a
     | block type no longer listed, an entry trashed since. A field declared
     | since keeps live's value, the revision having nothing to say about it.
     | An internal field comes back only for a caller who sees it, and is
     | named only to one. Null where nothing differs.
     */
    public function restoreRevision(int $id, bool $overrideAccess = false): ?Draft
    {
        $revision = DB::table(self::REVISIONS)->where('site_id', $this->site())->where('id', $id)->first()
            ?? throw new RecordNotFoundException("Revision {$id} is not there to restore.");
        $type = $this->drafted($this->handled($revision->type));
        $global = is_subclass_of($type, GlobalSet::class);
        $entry = $global ? null : (int) $revision->entry_id;

        [$row] = $this->live($type, $entry ?? 0, $overrideAccess);
        [$internal] = $this->drafter($type, $entry ?? 0, $row, $overrideAccess);

        $snapshot = $this->hidden($type, json_decode($revision->snapshot, true, flags: JSON_THROW_ON_ERROR), $internal);
        $fields = $this->mainstay->fields($type);
        $known = $global ? $fields : [...$fields, 'template' => null];
        $unrestored = [];
        $configured = array_keys($this->mainstay->locales());
        $locales = array_values(array_intersect($configured, array_keys($snapshot['locales'])));

        foreach (array_diff_key($snapshot['fields'], $known) as $name => $value) {
            $unrestored['fields'][$name] = 'The type no longer declares it.';
        }

        foreach ($snapshot['locales'] as $locale => $values) {
            foreach (in_array($locale, $locales, true) ? array_diff_key($values, $known) : $values as $name => $value) {
                $unrestored['locales'][$locale][$name] = in_array($locale, $locales, true) ? 'The type no longer declares it.' : "{$locale} is no longer a content locale.";
            }
        }

        return DB::transaction(function () use ($type, $entry, $snapshot, $known, $locales, &$unrestored, $overrideAccess) {
            $draft = null;

            foreach ($locales as $index => $locale) {
                $data = array_intersect_key([...($index === 0 ? $snapshot['fields'] : []), ...$snapshot['locales'][$locale]], $known);

                /* A value its field now refuses is left out, named, and the
                   rest saved without it. A refusal of nothing given -- a live
                   value that breaks a rule -- is the caller's, as on a save. */
                while (true) {
                    try {
                        $draft = $this->saveDraft($type, $data, entry: $entry, locale: $locale, overrideAccess: $overrideAccess);

                        break;
                    } catch (ValidationException $exception) {
                        if (array_intersect_key($data, array_flip(array_map(fn (string $key) => explode('.', $key)[0], array_keys($exception->errors())))) === []) {
                            throw $exception;
                        }

                        foreach ($exception->errors() as $key => $messages) {
                            $name = explode('.', $key)[0];

                            if (! array_key_exists($name, $data)) {
                                continue;
                            }

                            unset($data[$name]);

                            if (($known[$name] ?? null)?->localized) {
                                $unrestored['locales'][$locale][$name] = $messages[0];
                            } else {
                                $unrestored['fields'][$name] = $messages[0];
                            }
                        }
                    }
                }
            }

            return $draft === null ? null : new Draft($draft->id, $draft->entryId, $draft->entry, $draft->fields, $draft->locales, $draft->updatedAt, $unrestored);
        }, self::ATTEMPTS);
    }

    /*
     | Out of the trash, with its paths built again as a save builds them. A
     | path taken since is the one place a suffix is right: the slug ending
     | the pattern takes the first of `-2`, `-3` that frees it -- see
     | suffix() -- since refusing would hold the content hostage to a slug
     | somebody else claimed, and the caller is standing in front of the
     | change. Anything a suffix cannot free is refused, as a save is.
     |
     | Hands back the paths it took, by locale, to a caller that may read
     | the type.
     |
     | @return array<string, string>
     */
    public function restore(string $type, int $id, bool $overrideAccess = false): array
    {
        $type = $this->entry($type);
        $handle = $type::handle();
        $row = DB::table($handle)->where('site_id', $this->site())->where('id', $id)->whereNotNull('deleted_at')->first()
            ?? throw $this->missing($type, $overrideAccess, "{$type} {$id} is not in the trash to restore.");

        $this->attach($type, [$row]);
        [, $reads] = $this->writes($type, 'restore', fn () => $this->hydrate($type, $row, null, true), $overrideAccess);

        return DB::transaction(function () use ($type, $handle, $id, $reads) {
            $now = $this->stamp(CarbonImmutable::now());

            if (DB::table($handle)->where('id', $id)->whereNotNull('deleted_at')->update(['deleted_at' => null, 'updated_at' => $now]) === 0) {
                throw new RecordNotFoundException("{$type} {$id} left the trash while it was being restored.");
            }

            $this->suffix($type, $id);
            $this->paths($type, $id, $this->site(), false, $reads, $this->snapshotted(1));

            /* None to a caller that may not read the type: a path spells out
               the fields it is built from. */
            return $reads ? DB::table('uris')->where('type', $handle)->where('entry_id', $id)->pluck('uri', 'locale')->all() : [];
        }, self::ATTEMPTS);
    }

    /*
     | Out of the trash for good, and only out of the trash: the row, every
     | locale's, its draft, its revisions and its rows in every taxonomy's
     | pivot -- for a term, the rows pointing at it too, which its foreign
     | key needs gone first. What links to it read it as missing while it was
     | trashed, and go on doing so.
     */
    public function destroy(string $type, int $id, bool $overrideAccess = false): void
    {
        $type = $this->entry($type);
        $handle = $type::handle();
        $row = DB::table($handle)->where('site_id', $this->site())->where('id', $id)->whereNotNull('deleted_at')->first()
            ?? throw $this->missing($type, $overrideAccess, "{$type} {$id} is not in the trash to delete for good.");

        $this->attach($type, [$row]);
        $this->writes($type, 'forceDelete', fn () => $this->hydrate($type, $row, null, true), $overrideAccess);

        DB::transaction(function () use ($type, $handle, $id) {
            /* Held from here, so a restore arriving now waits and then finds
               nothing in the trash. */
            DB::table($handle)->where('id', $id)->whereNotNull('deleted_at')->lockForUpdate()->value('id')
                ?? throw new RecordNotFoundException("{$type} {$id} left the trash while it was being deleted for good.");

            if (is_subclass_of($type, Taxonomy::class)) {
                DB::table("{$handle}_entries")->where('term_id', $id)->delete();
            }

            foreach ($this->mainstay->fields($type) as $field) {
                if ($field instanceof Terms) {
                    DB::table($field->of::handle().'_entries')->where('entry_type', $handle)->where('entry_id', $id)->delete();
                }
            }

            foreach ([self::DRAFTS, self::REVISIONS] as $table) {
                DB::table($table)->where('site_id', $this->site())->where('type', $handle)->where('entry_id', $id)->delete();
            }

            DB::table('uris')->where('type', $handle)->where('entry_id', $id)->delete();
            DB::table("{$handle}_locales")->where('parent_id', $id)->delete();
            DB::table($handle)->where('id', $id)->delete();
        }, self::ATTEMPTS);
    }

    /*
     | A draft as the declared class in a locale: what is live there with the
     | draft's changes over it, what it points at loaded to `$depth` as any
     | read's. Null where the locale is neither live nor drafted, or the entry
     | is in the trash. Gate is asked as a save would, unless the caller
     | already asked and passes what it learned, `$internal`.
     */
    private function readDraft(string $type, object $draft, ?string $locale, bool $overrideAccess, int $depth, ?bool $internal = null): ?Draft
    {
        $locale = $this->locale($locale);
        $this->depth($depth);
        $global = is_subclass_of($type, GlobalSet::class);
        $entry = $draft->entry_id === null ? null : (int) $draft->entry_id;

        try {
            [$row, $translations] = $this->live($type, $entry, $overrideAccess);
        } catch (RecordNotFoundException) {
            return null;
        }

        $internal ??= $this->drafter($type, $entry, $row, $overrideAccess)[0];
        $changes = $this->changes($draft);

        if (! $translations->has($locale) && ! isset($changes['locales'][$locale])) {
            return null;
        }

        $translation = $translations->get($locale);
        $built = (object) ['id' => $row?->id, 'owner_id' => $row?->owner_id, 'created_at' => $row?->created_at, 'updated_at' => $row?->updated_at];

        if (! $global) {
            $built->template = array_key_exists('template', $changes['fields']) ? $changes['fields']['template'] : $row?->template;
            $built->uri = $row === null ? null : DB::table('uris')->where('type', $type::handle())->where('entry_id', $row->id)->where('locale', $locale)->value('uri');
        }

        foreach ($this->mainstay->fields($type) as $name => $field) {
            [$drafted, $source] = $field->localized ? [$changes['locales'][$locale] ?? [], $translation] : [$changes['fields'], $row];

            if (array_key_exists($name, $drafted)) {
                $built->{Str::snake($name)} = $drafted[$name];
            } elseif ($source !== null) {
                $built->{Str::snake($name)} = $source->{Str::snake($name)} ?? null;
            }
        }

        $shown = $this->hidden($type, $changes, $internal);

        return new Draft(
            (int) $draft->id,
            $global ? null : $entry,
            $this->hydrate($type, $built, $locale, $internal, loaded: $this->resolve([$type => [$built]], $locale, $overrideAccess, $depth), lenient: true),
            array_keys($shown['fields']),
            array_map('array_keys', $shown['locales']),
            $this->moment->cast($draft->updated_at),
        );
    }

    /* The type a stored handle names. */
    private function handled(string $handle): string
    {
        return $this->mainstay->registered()[$handle]
            ?? throw new InvalidArgumentException("{$handle} is not a registered content type, so its drafts and revisions cannot be read.");
    }

    /*
     | A type that has drafts and revisions: an entry or a global. A term is
     | written live, with no draft and no history.
     */
    private function drafted(string $type): string
    {
        $registered = $this->registered($type);

        if (is_subclass_of($registered, Taxonomy::class)) {
            throw new InvalidArgumentException("{$registered} is a taxonomy, whose terms are written live, with no drafts and no revisions. Write them with update().");
        }

        return $registered;
    }

    /*
     | What is live for a draft: the entry's row with its terms and every
     | locale's, out of the trash; a global's, this site's one or none yet;
     | nothing for an entry not published. `$entry` is the draft's: 0 for a
     | global.
     |
     | @return array{0: ?object, 1: Collection<string, object>}
     */
    private function live(string $type, ?int $entry, bool $overrideAccess): array
    {
        if (is_subclass_of($type, GlobalSet::class)) {
            $row = DB::table($type::handle())->where('site_id', $this->site())->first();

            return [$row, $row === null ? collect() : DB::table("{$type::handle()}_locales")->where('parent_id', $row->id)->get()->keyBy('locale')];
        }

        return $entry === null ? [null, collect()] : $this->load($type, $entry, $overrideAccess);
    }

    /*
     | Gate, for a draft, as the write it becomes would ask: `create` about
     | the type for an entry not published yet, `update` about the entry for
     | one that is, and about the type for a global. Neither is public.
     |
     | @return array{0: bool, 1: bool}
     */
    private function drafter(string $type, ?int $entry, ?object $row, bool $overrideAccess): array
    {
        return match (true) {
            is_subclass_of($type, GlobalSet::class) => $this->writes($type, 'update', $type, $overrideAccess),
            $entry === null => $this->writes($type, 'create', $type, $overrideAccess),
            default => $this->writes($type, 'update', fn () => $this->hydrate($type, $row, null, true), $overrideAccess),
        };
    }

    /* The draft of an entry, by its id or 0 for a global, read with a lock
       where a plain read could miss one another connection committed. */
    private function draftFor(string $type, int $entry, bool $lock = false): ?object
    {
        return DB::table(self::DRAFTS)->where('site_id', $this->site())->where('type', $type::handle())->where('entry_id', $entry)
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->first();
    }

    /** @return array{fields: array<string, mixed>, locales: array<string, array<string, mixed>>} */
    private function changes(object $draft): array
    {
        return ['fields' => [], 'locales' => [], ...json_decode($draft->changes, true, flags: JSON_THROW_ON_ERROR)];
    }

    /* A draft's or a revision's shape without the internal fields, for a
       caller who may not see them. */
    private function hidden(string $type, array $shape, bool $internal = false): array
    {
        if ($internal) {
            return $shape;
        }

        $internals = array_filter($this->mainstay->fields($type), fn (Field $field) => $field->internal);

        return [
            'fields' => array_diff_key($shape['fields'], $internals),
            'locales' => array_map(fn (array $values) => array_diff_key($values, $internals), $shape['locales']),
        ];
    }

    /*
     | Runs `$write`, and where it replaces something live -- a value a
     | locale already held, not a translation added -- files the entry as it
     | was before, once for the call however many locales it writes. None for
     | an entry being created, which replaces nothing, and none for a term,
     | which has no history.
     |
     | The row is locked before the entry is read, so a write beside this one
     | waits rather than filing what this one is about to replace.
     */
    private function filed(string $type, ?int $id, Closure $write): mixed
    {
        if ($id === null || is_subclass_of($type, Taxonomy::class)) {
            return $write();
        }

        return DB::transaction(function () use ($type, $id, $write) {
            $before = $this->snapshot($type, $id, lock: true);
            $written = $write();

            if ($this->replaced($before, $this->snapshot($type, $id))) {
                $this->file($type, $id, $before);
            }

            return $written;
        }, self::ATTEMPTS);
    }

    /*
     | The whole entry as it is now -- its shared fields, every locale's
     | localized ones, its view and its terms -- each as its field serializes
     | it, the shape a draft holds. Read in the transaction filed() opened:
     | with a lock where a plain read would answer from a caller's older
     | snapshot -- see snapshotted().
     */
    private function snapshot(string $type, int $id, bool $lock = false): array
    {
        $handle = $type::handle();
        $nested = $this->snapshotted(1);
        $row = DB::table($handle)->where('id', $id)->whereNull('deleted_at')->when($lock, fn (Builder $query) => $query->lockForUpdate())->first()
            ?? throw new RecordNotFoundException("{$type} {$id} was deleted while it was being saved.");

        $this->attach($type, [$row], $nested);

        $shape = ['fields' => is_subclass_of($type, Entry::class) ? ['template' => $row->template] : [], 'locales' => []];
        $fields = $this->mainstay->fields($type);

        foreach ($fields as $name => $field) {
            if (! $field->localized) {
                $shape['fields'][$name] = $this->normal($field, $row->{Str::snake($name)} ?? null);
            }
        }

        foreach (DB::table("{$handle}_locales")->where('parent_id', $id)->when($nested, fn (Builder $query) => $query->lockForUpdate())->get() as $translation) {
            $shape['locales'][$translation->locale] = [];

            foreach ($fields as $name => $field) {
                if ($field->localized) {
                    $shape['locales'][$translation->locale][$name] = $this->normal($field, $translation->{Str::snake($name)} ?? null);
                }
            }
        }

        return $shape;
    }

    /* Whether something `$before` held is not what `$after` holds: a value
       replaced, rather than a translation added. */
    private function replaced(array $before, array $after): bool
    {
        foreach ($before['fields'] as $name => $value) {
            if (! array_key_exists($name, $after['fields']) || $after['fields'][$name] !== $value) {
                return true;
            }
        }

        foreach ($before['locales'] as $locale => $values) {
            foreach ($values as $name => $value) {
                if (! array_key_exists($name, $after['locales'][$locale] ?? []) || $after['locales'][$locale][$name] !== $value) {
                    return true;
                }
            }
        }

        return false;
    }

    /*
     | A revision of what was live, and the entry's oldest pruned past
     | `mainstay.revisions`: by id, which orders them where `created_at` is
     | only to the second, and by primary key, so the delete locks those rows
     | and no gap beside them.
     */
    private function file(string $type, int $id, array $snapshot): void
    {
        $key = ['site_id' => $this->site(), 'type' => $type::handle(), 'entry_id' => is_subclass_of($type, GlobalSet::class) ? 0 : $id];

        DB::table(self::REVISIONS)->insert([...$key, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => $this->stamp(CarbonImmutable::now())]);

        if (($keep = config('mainstay.revisions')) === null) {
            return;
        }

        $gone = array_slice(DB::table(self::REVISIONS)->where($key)->orderByDesc('id')->pluck('id')->all(), (int) $keep);

        if ($gone !== []) {
            DB::table(self::REVISIONS)->whereIntegerInRaw('id', $gone)->delete();
        }
    }

    /*
     | A stored value as its field serializes it, read through its cast first
     | so a date, a number or a boolean is one shape whichever driver handed
     | it back, and a map's keys in one order -- MySQL sorts a JSON object's.
     | What its field cannot read is compared as it is stored.
     */
    private function normal(Field $field, mixed $value): mixed
    {
        try {
            return $this->canonical($field->serialize($field->cast($field->decode($value))));
        } catch (InvalidArgumentException|JsonException) {
            return $this->canonical($value);
        }
    }

    /* A value a draft is given, as its field serializes it, or null for one
       left empty that the field has no empty value for. */
    private function kept(Field $field, mixed $value): mixed
    {
        try {
            return $this->canonical($field->serialize($value));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /*
     | Where restoring from the trash finds a locale's path taken, the field
     | ending that locale's pattern -- a text slug -- takes the first of
     | `-2`, `-3` giving a free path in every locale built from it: a shared
     | slug in every locale. Cut short where the suffix would pass the
     | field's max or a path's 255 characters. A pattern ending otherwise, in
     | a literal segment or a select whose options a suffix would leave, or a
     | path another route or locale answers, which no suffix frees, is left
     | for paths() to refuse, naming it.
     */
    private function suffix(string $type, int $id): void
    {
        if (($patterns = $this->patterns($type)) === null) {
            return;
        }

        $handle = $type::handle();
        $site = $this->site();
        $fields = $this->mainstay->fields($type);
        $taken = fn (string $locale, string $uri) => DB::table('uris')->where('site_id', $site)->where('locale', $locale)->where('uri', $uri)->exists();

        foreach (array_keys($patterns) as $locale) {
            $row = DB::table($handle)->where('id', $id)->first();
            $translations = DB::table("{$handle}_locales")->where('parent_id', $id)->get()->keyBy('locale');

            if (! $translations->has($locale) || ! $taken($locale, $this->built($type, $patterns[$locale], $row, $translations[$locale]))) {
                continue;
            }

            $field = preg_match('/'.Route::PLACEHOLDER.'\z/', $patterns[$locale], $last) ? $fields[$last[1]] : null;

            if (! $field instanceof Text && ! $field instanceof Textarea) {
                continue;
            }

            $value = ($field->localized ? $translations[$locale] : $row)->{Str::snake($field->name)};
            /* Every locale whose path this field builds. */
            $built = $field->localized ? [$locale] : array_values(array_filter($translations->keys()->all(), fn (string $other) => isset($patterns[$other]) && str_contains($patterns[$other], "{{$field->name}}")));

            for ($n = 2; ; $n++) {
                $room = min($field instanceof Text ? $field->max : PHP_INT_MAX, ...array_map(fn (string $other) => 255 - mb_strlen($this->built($type, $patterns[$other], $row, $translations[$other], [$field->name => ''])), $built)) - strlen("-{$n}");
                $base = rtrim(mb_substr($value, 0, max($room, 0)), '-');

                if ($room < 1 || $base === '') {
                    break;
                }

                $candidate = "{$base}-{$n}";
                $free = true;

                foreach ($built as $other) {
                    $uri = $this->built($type, $patterns[$other], $row, $translations[$other], [$field->name => $candidate]);

                    if ($taken($other, $uri) || $this->mainstay->shadow($other, $uri) !== null) {
                        $free = false;

                        break;
                    }
                }

                if ($free) {
                    ($field->localized ? DB::table("{$handle}_locales")->where('parent_id', $id)->where('locale', $locale) : DB::table($handle)->where('id', $id))
                        ->update([Str::snake($field->name) => $candidate]);

                    break;
                }
            }
        }
    }

    /*
     | What a write committed, whether or not the caller may read the type. A
     | public form that may create submissions and not read them would
     | otherwise commit the row and then be told it failed. A caller that may
     | not read is handed only what it wrote -- `$only`, the keys it gave --
     | and never an internal field it may not see. Gone only if a delete
     | landed after the commit, which is what the caller is then told. What
     | it points at is loaded as a read at the default depth would load it.
     */
    private function readBack(string $type, int $id, string $locale, bool $internal, ?array $only, bool $overrideAccess): ContentType
    {
        $row = $this->select($type, $locale, ['id' => $id], [], $internal)->first()
            ?? throw new RecordNotFoundException("{$type} {$id} was written and is gone from {$locale}.");

        $this->attach($type, [$row]);

        return $this->hydrate($type, $row, $locale, $internal, $only, $this->resolve([$type => [$row]], $locale, $overrideAccess, self::DEPTH));
    }

    /* A depth below none is a read that follows less than nothing. */
    private function depth(int $depth): void
    {
        if ($depth < 0) {
            throw new InvalidArgumentException("A read follows its relations to a depth of 0 or more; {$depth} is not one.");
        }
    }

    /*
     | What the rows point at, loaded, for hydrate() to hand the fields
     | casting them: by class and then id. The images of every row, whatever
     | the depth, since an image leads nowhere further; and at a depth of one
     | or more the entries their relations and terms name, read as their own
     | type's read would read them a level less deep. One query per class a
     | level, however many rows -- the images, then each type the references
     | name -- inlined with whereIntegerInRaw past SQL Server's 2100
     | parameters, and the pivot of each Terms field the entries read hold.
     |
     | An entry not loaded -- the depth spent, or the entry trashed, gone,
     | untranslated in the locale, on another site, or of a type the caller
     | may not read -- is left for its field to read as one marked missing.
     | A reference to a type that is not registered is refused here, at any
     | depth. A cycle costs only the levels asked for.
     |
     | @param  array<class-string, iterable<object>>  $rows  by type
     | @return array<class-string, array<int, object>>
     */
    private function resolve(array $rows, string $locale, bool $overrideAccess, int $depth): array
    {
        $ids = [];

        foreach ($rows as $type => $list) {
            $internal = $this->sees($type, $overrideAccess);

            foreach ($list as $row) {
                foreach ($this->mainstay->fields($type) as $name => $field) {
                    $column = Str::snake($name);

                    if (! property_exists($row, $column) || ($field->internal && ! $internal)) {
                        continue;
                    }

                    /* A column that does not parse points at nothing;
                       hydrate() names it. */
                    try {
                        foreach ($field->references($field->decode($row->{$column}), $name) as [$class, $id]) {
                            $ids[$class][$id] = $id;
                        }
                    } catch (JsonException) {
                        continue;
                    }
                }
            }
        }

        $loaded = [Media::class => $this->mainstay->media()->load(array_values($ids[Media::class] ?? []), $locale)];
        unset($ids[Media::class]);

        $types = array_map($this->entry(...), array_combine(array_keys($ids), array_keys($ids)));

        if ($depth < 1 || $ids === []) {
            return $loaded;
        }

        $next = [];

        foreach ($ids as $class => $list) {
            $target = $types[$class];

            if ($overrideAccess || $this->gate($target)->allows('viewAny', $target)) {
                $next[$target] = $this->query($target, $locale)->whereIntegerInRaw("{$target::handle()}.id", array_values($list))->get();
                $this->attach($target, $next[$target]);
            }
        }

        $deeper = $this->resolve($next, $locale, $overrideAccess, $depth - 1);

        foreach ($next as $target => $list) {
            foreach ($list as $row) {
                $loaded[$target][(int) $row->id] = $this->hydrate($target, $row, $locale, $this->sees($target, $overrideAccess), loaded: $deeper);
            }
        }

        return $loaded;
    }

    /* Whether the caller sees a type's internal fields: its own policy's
       answer, asked once a call, for the type read and every type it points
       at. */
    private function sees(string $type, bool $overrideAccess): bool
    {
        return $overrideAccess || ($this->sees[$type] ??= $this->gate($type)->allows('viewInternal', $type));
    }

    /*
     | Each row's terms, read from its taxonomy's pivot and set on the row
     | under the field's column as the ids it holds in order, so every read,
     | save and check after this takes it as it takes a column. One query per
     | Terms field for all the rows.
     */
    private function attach(string $type, iterable $rows, bool $lock = false): void
    {
        $rows = collect($rows);

        foreach ($this->mainstay->fields($type) as $name => $field) {
            if (! $field instanceof Terms) {
                continue;
            }

            $this->entry($field->of);

            $held = $rows->isEmpty() ? collect() : DB::table($field->of::handle().'_entries')
                ->where('entry_type', $type::handle())
                ->whereIntegerInRaw('entry_id', $rows->map(fn (object $row) => (int) $row->id)->all())
                ->orderBy('position')
                ->orderBy('id')
                ->when($lock, fn (Builder $query) => $query->lockForUpdate())
                ->get(['entry_id', 'term_id'])
                ->groupBy(fn (object $pivot) => (int) $pivot->entry_id);

            foreach ($rows as $row) {
                $row->{Str::snake($name)} = $held->get((int) $row->id, collect())->map(fn (object $pivot) => (int) $pivot->term_id)->values()->all();
            }
        }
    }

    /*
     | The entry type as registered, which is the spelling every other lookup
     | keys on. A term is an entry. A global is refused rather than read as
     | one: it is one row per site, which these calls do not hold to.
     */
    private function entry(string $type): string
    {
        $registered = $this->registered($type);

        if (! is_subclass_of($registered, Entry::class)) {
            throw new InvalidArgumentException("{$registered} is a global. Read it with Mainstay::global() and write it with Mainstay::saveGlobal().");
        }

        return $registered;
    }

    private function globalSet(string $type): string
    {
        $registered = $this->registered($type);

        if (! is_subclass_of($registered, GlobalSet::class)) {
            throw new InvalidArgumentException("{$registered} is not a global. Read entries with find() and write them with create() and update().");
        }

        return $registered;
    }

    private function registered(string $type): string
    {
        $registered = is_subclass_of($type, ContentType::class)
            ? $this->mainstay->registered()[$type::handle()] ?? null
            : null;

        if ($registered === null || strcasecmp(ltrim($type, '\\'), $registered) !== 0) {
            throw new InvalidArgumentException("{$type} is not a registered content type. Register it with Mainstay::types().");
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
        $locales = array_keys($this->mainstay->locales());

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
     | EntryPolicy answers for the type unless the host chose another --
     | GlobalPolicy for a global. Found
     | the way Laravel finds a policy -- Gate::policy() on the type,
     | #[UsePolicy] on it, Gate::policy() on a class or interface it extends
     | -- with one step taken out: guessing by name, turned off on the copy
     | forUser() hands back. Laravel would match App\Policies\PostPolicy to any
     | class called Post, and a host's Eloquent Post and a content type called
     | Post are different things: the host's policy, typed for its own users,
     | would refuse every public read.
     |
     | On the copy, so the host's own Gate is left as Laravel would have it.
     | Looked up on every call, so a policy the host registers later answers.
     */
    private function gate(string $type): AccessGate
    {
        $gate = Gate::forUser($this->user())->guessPolicyNamesUsing(fn () => []);

        return $gate->getPolicyFor($type) === null
            ? $gate->policy($type, is_subclass_of($type, GlobalSet::class) ? GlobalPolicy::class : EntryPolicy::class)
            : $gate;
    }

    /*
     | Whether the caller sees internal fields, and whether it may read the
     | type, after asking whether it may make this write at all. The entry
     | Gate is asked about comes as a closure, so a write that skips Gate
     | never builds it.
     |
     | @return array{0: bool, 1: bool}
     */
    private function writes(string $type, string $ability, string|Closure $subject, bool $overrideAccess): array
    {
        if ($overrideAccess) {
            return [true, true];
        }

        $gate = $this->gate($type);
        $reads = $gate->allows('viewAny', $type);
        $response = $gate->inspect($ability, $subject instanceof Closure ? $subject() : $subject);

        /* Only where an entry is involved: a refused create has no entry
           whose being there could leak, and keeps the policy's own words. */
        if ($response->denied() && ! $reads && $ability !== 'create') {
            throw $this->refused($type);
        }

        $response->authorize();

        return [$gate->allows('viewInternal', $type), $reads];
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
     | one row at most, each by a unique index, which is what keeps
     | paginate's count honest.
     |
     | Only the main row's `deleted_at` is asked about. The sibling has one
     | too, for trashing a single translation, and nothing sets it yet.
     |
     | A global has neither a path nor a view of its own, and may name a field
     | `template`, so its read leaves both out.
     */
    private function query(string $type, string $locale): Builder
    {
        $handle = $type::handle();
        $locales = "{$handle}_locales";
        $routed = is_subclass_of($type, Entry::class);
        [$localized, $shared] = $this->columns($type);

        return DB::table($handle)
            ->join($locales, fn (JoinClause $join) => $join
                ->on("{$locales}.parent_id", '=', "{$handle}.id")
                ->where("{$locales}.locale", $locale))
            ->when($routed, fn (Builder $query) => $query->leftJoin('uris', fn (JoinClause $join) => $join
                ->on('uris.entry_id', '=', "{$handle}.id")
                ->on('uris.site_id', '=', "{$handle}.site_id")
                ->where('uris.type', $handle)
                ->where('uris.locale', $locale)))
            ->where("{$handle}.site_id", $this->site())
            ->whereNull("{$handle}.deleted_at")
            ->select([
                ...array_map(fn (string $column) => "{$handle}.{$column}", ['id', 'owner_id', ...($routed ? ['template'] : []), 'created_at', 'updated_at', ...array_keys($shared)]),
                ...array_map(fn (string $column) => "{$locales}.{$column}", array_keys($localized)),
                ...($routed ? ['uris.uri'] : []),
            ]);
    }

    /*
     | The fields by column, localized ones and the rest, split the way
     | ContentSchema::tables() splits them onto the two tables. A field kept
     | in rows of its own has no column on either.
     |
     | @return array{0: array<string, Field>, 1: array<string, Field>}
     */
    private function columns(string $type): array
    {
        return collect($this->mainstay->fields($type))
            ->reject(fn (Field $field) => $field->column() === null)
            ->keyBy(fn (Field $field) => Str::snake($field->name))
            ->partition(fn (Field $field) => $field->localized)
            ->map->all()
            ->all();
    }

    /* The first site, read once for this store. Mainstay makes a store for
       every call, so nothing is kept past the call -- under Octane either --
       and phase 12 matches the request's host here. */
    private function site(): int
    {
        return $this->site ??= (int) (DB::table('sites')->orderBy('id')->value('id')
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

        if ($field instanceof Terms) {
            $this->tagged($query, $type, $key, $field, is_array($condition) ? $condition : ['=' => $condition]);

            return;
        }

        foreach (is_array($condition) ? $condition : ['=' => $condition] as $operator => $value) {
            if (! in_array($operator, self::OPERATORS, true)) {
                throw new InvalidArgumentException("{$operator} is not an operator a where takes. It takes ".implode(', ', self::OPERATORS).'.');
            }

            if ($operator === 'in' || $operator === 'not_in') {
                $this->within($query, $column, array_map(fn (mixed $one) => $this->value($field, $key, $this->single($key, $operator, $one)), (array) $value), $operator === 'not_in');

                continue;
            }

            /* Refused as the caller wrote it, before the value becomes what
               its column holds: a field that cannot hold null would turn it
               into its empty value, and `featured < null` would quietly
               compare with false. */
            if ($value === null && $operator !== '=' && $operator !== '!=') {
                throw new InvalidArgumentException("{$key} is compared with {$operator} against nothing. Only = and != take null.");
            }

            $value = $this->value($field, $key, $this->single($key, $operator, $value));

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

    /*
     | The reverse query: entries holding a term, `=`, or any of several,
     | `in` -- an exists on the taxonomy's pivot, so it pages and sorts as any
     | filter does. Only a term out of the trash counts, so a trashed one
     | gathers nothing while its rows wait for its restore. The other
     | operators wait for something that needs them.
     */
    private function tagged(Builder $query, string $type, string $key, Terms $field, array $condition): void
    {
        $handle = $type::handle();
        $taxonomy = $field->of::handle();

        foreach ($condition as $operator => $value) {
            if ($operator !== '=' && $operator !== 'in') {
                throw new InvalidArgumentException("{$key} holds terms, and a where finds the entries holding one with = or any of several with in.");
            }

            $terms = array_map(fn (mixed $term) => $this->reference($key, $field, $term), $operator === 'in' ? (array) $value : [$this->single($key, $operator, $value)]);

            $query->whereExists(fn (Builder $exists) => $exists->selectRaw('1')
                ->from("{$taxonomy}_entries as mainstay_pivot")
                ->join("{$taxonomy} as mainstay_term", 'mainstay_term.id', '=', 'mainstay_pivot.term_id')
                ->whereColumn('mainstay_pivot.entry_id', "{$handle}.id")
                ->where('mainstay_pivot.entry_type', $handle)
                ->whereNull('mainstay_term.deleted_at')
                ->whereIn('mainstay_pivot.term_id', $terms));
        }
    }

    /*
     | What a where names on a relation of one type or on terms: an entry of
     | that type, or its id. An entry of another type would compare its id
     | with another table's, and a word would match nothing without saying
     | so, so both are refused.
     */
    private function reference(string $key, Relation $field, mixed $value): int
    {
        $type = $field->to[0];
        $id = match (true) {
            $value instanceof Entry => $value::class === $type ? $value->id : false,
            is_int($value), is_string($value) => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            default => false,
        };

        return $id === false ? throw new InvalidArgumentException("The condition on {$key} names something that is not a {$type::handle()} or a {$type::handle()}'s id.") : $id;
    }

    /* in and not_in, with a null among the values meaning what = and !=
       mean by it. Left to SQL, `in (null)` matches nothing and
       `not in (..., null)` is never true, so a list stops working the moment
       it holds one. */
    private function within(Builder $query, string $column, array $values, bool $not): void
    {
        $null = in_array(null, $values, true);
        $values = array_values(array_filter($values, fn (mixed $one) => $one !== null));

        if ($not) {
            $query->whereNotIn($column, $values)->when($null, fn (Builder $query) => $query->whereNotNull($column));

            return;
        }

        $query->where(fn (Builder $any) => $any->whereIn($column, $values)->when($null, fn (Builder $any) => $any->orWhereNull($column)));
    }

    /* One value, not a list. Laravel compares with a list's first element
       and drops the rest, which is a condition nobody wrote. */
    private function single(string $key, string $operator, mixed $value): mixed
    {
        if (is_array($value)) {
            throw new InvalidArgumentException("The condition on {$key} compares {$operator} with a list. Only in and not_in take one, and they take a list of values.");
        }

        return $value;
    }

    private function sort(Builder $query, string $type, string $key, bool $internal): void
    {
        $descending = str_starts_with($key, '-');
        $key = $descending ? substr($key, 1) : $key;
        [$column, $field] = $this->column($type, $key, $internal);

        if ($column === null) {
            throw new InvalidArgumentException("{$type}::\${$key} holds terms, which are not sorted on.");
        }

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
     | stores it -- null for a stamp. No column for a field kept in rows of
     | its own, which the caller filters on by those.
     |
     | An internal field the caller cannot see is refused in the words an
     | unknown one is. A refusal of its own would confirm the field exists,
     | and a filter on it would read its value back off which entries match.
     |
     | @return array{0: ?string, 1: ?Field}
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

        if ($field->column() === null) {
            return [null, $field];
        }

        /* A tree is not a value to compare, and Postgres has no operator to
           compare a json column with anyway. */
        if ($field->keptAsJson()) {
            throw new InvalidArgumentException("{$type}::\${$key} is kept as JSON, which is not filtered or sorted on.");
        }

        return [($field->localized ? "{$handle}_locales" : $handle).'.'.Str::snake($key), $field];
    }

    /*
     | The value as the column holds it, so the database compares like with
     | like: a date in another zone becomes the UTC string stored, rather than
     | being formatted in its own zone and compared off by the offset.
     |
     | Null too. A field that cannot hold null stores its empty value when
     | written one, so asking for null asks for that -- `featured => null`
     | finds what a write left unset, false, rather than rows no write makes.
     | A field with no empty value, a required select or date, is left null:
     | its column is NOT NULL, and matching nothing is the right answer.
     */
    private function value(?Field $field, string $key, mixed $value): mixed
    {
        return match (true) {
            $field !== null && ($value === null || ($field instanceof Relation && blank($value))) => $this->unset($field),
            $field instanceof Relation => $this->reference($key, $field, $value),
            $field !== null => $field->serialize($value),
            $value === null => null,
            $key === 'id' => (int) $value,
            /* Blank as a field reads blank: nothing, rather than a stamp. */
            blank($value) => null,
            default => $this->stamp($value),
        };
    }

    /* What a field stores when it is written null, or null for one with no
       empty value to store. */
    private function unset(Field $field): mixed
    {
        try {
            return $field->serialize(null);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function stamp(mixed $value): string
    {
        return $this->moment->serialize($value);
    }

    /*
     | The main row, with its terms, and every locale's row, by locale. Out of
     | the trash and on this site, or not found -- see missing().
     |
     | @return array{0: object, 1: Collection<string, object>}
     */
    private function load(string $type, int $id, bool $overrideAccess, bool $lock = false): array
    {
        $handle = $type::handle();

        $row = DB::table($handle)
            ->where('site_id', $this->site())
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->first()
            ?? throw $this->missing($type, $overrideAccess, "{$type} {$id} is not there to write.");

        $this->attach($type, [$row]);

        return [$row, DB::table("{$handle}_locales")->where('parent_id', $id)->when($lock, fn (Builder $query) => $query->lockForUpdate())->get()->keyBy('locale')];
    }

    /*
     | Whether a plain read here answers from a snapshot older than what is
     | committed: inside a transaction deeper than the `$own` this code opened,
     | on MySQL or MariaDB, whose REPEATABLE READ keeps one snapshot for the
     | whole transaction. Postgres and SQL Server read what is committed at
     | each statement, and a transaction of the layer's own starts its
     | snapshot at its first plain read, after its writes.
     */
    private function snapshotted(int $own): bool
    {
        return DB::transactionLevel() > $own && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /*
     | Not found, to a caller that may read the type, which Laravel answers
     | with a 404; refused, to one that may not. The load comes before Gate,
     | which is asked about the entry loaded, so a 404 for a missing id beside
     | a 403 for a present one would let a caller with no rights at all count
     | the entries and their translations. A reader could find() them anyway.
     */
    private function missing(string $type, bool $overrideAccess, string $message): RecordNotFoundException|AuthorizationException
    {
        return $overrideAccess || $this->gate($type)->allows('viewAny', $type)
            ? new RecordNotFoundException($message)
            : $this->refused($type);
    }

    /*
     | The refusal a caller that may not read the type is given for a write to
     | an entry it may not make, the entry there or not: Gate's default denial,
     | whatever the policy said. A policy's own message or status for a
     | present entry, beside a default for a missing one, would tell the two
     | apart the way a 404 beside a 403 did. Asked for an ability nobody
     | defines, which is how Gate hands out its default, the host's own
     | defaultDenialResponse() included.
     */
    private function refused(string $type): AuthorizationException
    {
        try {
            $this->gate($type)->inspect('mainstay.refused')->authorize();
        } catch (AuthorizationException $exception) {
            return $exception;
        }

        return new AuthorizationException;
    }

    /*
     | What is stored, by property, as the columns hold it. Not through cast():
     | a translation not written yet has no row, and cast() refuses the null
     | a required select or date would read as. Its localized keys are left
     | out instead, which the validator then reports as missing.
     |
     | Except a tree, which the rules check as the array a read hands back
     | and not as the string it is kept in. Decoded, and left as data rather
     | than read through cast() -- validate() completes it as a read would
     | see it -- so a value inside it that breaks a rule is refused naming
     | where it is, as a column's is. One that does not decode stays the
     | string it is, which the rules refuse by name. An internal one the
     | caller may not see is not decoded at all: nothing checks it, and no
     | error may name it.
     */
    private function stored(string $type, object $row, ?object $translation, bool $internal): array
    {
        $stored = [];

        foreach ($this->mainstay->fields($type) as $name => $field) {
            $source = $field->localized ? $translation : $row;

            if ($source !== null) {
                $value = $source->{Str::snake($name)};

                try {
                    $stored[$name] = $internal || ! $field->internal ? $field->decode($value) : $value;
                } catch (JsonException) {
                    $stored[$name] = $value;
                }
            }
        }

        return $stored;
    }

    /* A column that does not parse was written around the layer, and is
       named as a row a field cannot read is. */
    private function decode(string $type, Field $field, mixed $value): mixed
    {
        try {
            return $field->decode($value);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("{$type}::\${$field->name} holds something that is not JSON: {$exception->getMessage()}.", previous: $exception);
        }
    }

    /*
     | The entry as it will be stored -- what is there, with what was given
     | over it -- checked against the declared rules. Every stored value is
     | checked again, not only the ones given: a row that went in some other
     | way and breaks a rule refuses the next save, naming the field.
     |
     | Only the fields the caller can see. An internal one is not a field to
     | a caller who may not read it: writing it is refused in the words an
     | unknown key is, and what is stored in it is not checked, so no error
     | names it either.
     */
    private function validate(string $type, array $data, array $stored, bool $internal, bool $overrideAccess, bool $draft = false): array
    {
        $fields = array_filter($this->mainstay->fields($type), fn (Field $field) => $internal || ! $field->internal);
        /* An entry's own view is a key of its own beside the fields. A global
           has none, and `template` is a field's name there like any other. */
        $entry = is_subclass_of($type, Entry::class);

        if (($unknown = array_diff_key($data, $fields, $entry ? ['template' => null] : [])) !== []) {
            throw new InvalidArgumentException("{$type} has no field called ".implode(' or ', array_keys($unknown)).' to write.');
        }

        /* A taxonomy that is not registered has no pivot to write to, which
           is refused before the row is. */
        foreach ($this->mainstay->fields($type) as $field) {
            if ($field instanceof Terms) {
                $this->entry($field->of);
            }
        }

        /* What a read handed out -- a document, a list of blocks, a block
           inside another's data -- given back as the arrays it was read
           from, all the way down, which is what the rules check. */
        $plain = function (mixed $value) use (&$plain): mixed {
            $value = $value instanceof Arrayable ? $value->toArray() : $value;

            return is_array($value) ? array_map($plain, $value) : $value;
        };
        $data = array_map($plain, $data);

        $values = [...$stored, ...$data];

        /* A field left off a row being inserted -- nothing is stored for it
           yet -- takes the default its property declares, whoever writes it.
           An internal one the caller may not see, required and with no
           default, has nothing valid to hold, and the write is refused as a
           question of access, which it is, without naming the field. */
        foreach (array_diff_key($this->mainstay->fields($type), $data, $stored) as $name => $field) {
            if ($field->hasDefault) {
                $values[$name] = $field->default;
            } elseif (! $draft && ! $internal && $field->internal && $field->isRequired()) {
                throw new AuthorizationException('Writing a '.class_basename($type).' needs a field this caller may not see. Give it a default in the declaration, make it optional, or write with access to it.');
            }
        }

        /* Each value as a read would hand it back, still as data: a field a
           block was written without holds its default, and a block of a type
           its field no longer lists is left out of what is stored. */
        foreach ($fields as $name => $field) {
            if (array_key_exists($name, $values)) {
                $values[$name] = $field->complete($values[$name], array_key_exists($name, $stored) && ! array_key_exists($name, $data));
            }
        }

        $routed = $this->placeholders($type);
        $rules = $messages = [];

        foreach ($fields as $name => $field) {
            $rules = [...$rules, ...$field->rulesAt($name, $values[$name] ?? null, $draft)];
        }

        foreach ($routed as $name) {
            $rules[$name][] = 'regex:'.Route::SLUG;
            $messages["{$name}.regex"] = 'The :attribute field is part of a path: lowercase letters, digits and single hyphens, the way Str::slug() writes one.';
        }

        /* The entry's own view, one its type lists, since a view is written
           against a type's fields. Checked when it is written and not again:
           a view taken off the list since breaks the page, rather than every
           save of the entry after it. */
        if ($entry && array_key_exists('template', $data)) {
            $views = $this->mainstay->templates($type);
            $rules['template'] = ['bail', 'nullable', 'string', Rule::in($views)];
            $messages['template.in'] = 'The :attribute field is one of the views this type renders with: '.implode(', ', $views).'.';
        }

        $validator = Validator::make($values, $rules, $messages);

        /*
         | What JSON cannot hold -- bytes that are not UTF-8, a number too large
         | to be finite once its field serializes it -- passes every rule a
         | value inside the tree answers to, and the encoder would refuse it
         | inside the write. Asked once the rules pass, of what the write
         | encodes: the value serialized, where it is given or a new row takes
         | it. What a row holds already came out of JSON.
         */
        $validator->after(function () use ($validator, $fields, $values, $data, $stored, $draft) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($fields as $name => $field) {
                /* A draft keeps a value left empty as null, which a field
                   with no empty value would refuse to serialize. */
                if (! $field->keptAsJson() || (! array_key_exists($name, $data) && array_key_exists($name, $stored)) || ($draft && ($values[$name] ?? null) === null)) {
                    continue;
                }

                try {
                    $field->encode($field->serialize($values[$name] ?? null));
                } catch (JsonException $exception) {
                    $validator->errors()->add($name, "The {$validator->getDisplayableAttribute($name)} field holds something JSON cannot: {$exception->getMessage()}.");
                }
            }
        });

        /*
         | What a save is given has to be there: an image in the library and
         | out of its trash, an entry a relation or a Terms field names out of
         | its trash on this site, in any locale -- one not translated into
         | this one is written, and reads as missing here. Asked of what the
         | call gives that the field does not hold already: an image or entry
         | trashed since it was chosen is the field's to keep while it is
         | away, and a list written back as a read handed it out gives it
         | again. An entry of a type the caller may not read is not there to
         | it, in the words an absent one is not.
         */
        $validator->after(function () use ($validator, $fields, $values, $data, $stored, $overrideAccess) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $given = [];

            foreach ($fields as $name => $field) {
                if (! array_key_exists($name, $data)) {
                    continue;
                }

                $held = array_map(fn (array $reference) => implode(':', $reference), $field->references($stored[$name] ?? null, $name));

                foreach ($field->references($values[$name] ?? null, $name) as $at => [$class, $id]) {
                    if (! in_array("{$class}:{$id}", $held, true)) {
                        $given[$class][$at] = $id;
                    }
                }
            }

            foreach ($given as $class => $ids) {
                $present = $class === Media::class
                    ? $this->mainstay->media()->present(array_values($ids))
                    : $this->present($this->entry($class), array_values(array_unique($ids)), $overrideAccess);

                foreach (array_diff($ids, $present) as $at => $id) {
                    $validator->errors()->add($at, $class === Media::class
                        ? "The {$validator->getDisplayableAttribute($at)} field names image {$id}, which is not in the media library or is in its trash."
                        : "The {$validator->getDisplayableAttribute($at)} field names {$class::handle()} {$id}, which is not there to point at.");
                }
            }
        });

        $validator->validate();

        return $values;
    }

    /*
     | Which of `$ids` are entries of `$type` out of the trash on this site,
     | in any locale. None, to a caller that may not read the type: what a
     | refusal names is then what the caller gave, and not which of it is
     | there.
     |
     | @return list<int>
     */
    private function present(string $type, array $ids, bool $overrideAccess): array
    {
        if ($ids === [] || ! ($overrideAccess || $this->gate($type)->allows('viewAny', $type))) {
            return [];
        }

        return DB::table($type::handle())->where('site_id', $this->site())->whereNull('deleted_at')->whereIntegerInRaw('id', $ids)
            ->pluck('id')->map(fn (mixed $id) => (int) $id)->all();
    }

    /*
     | The row, the locale's row, its terms and every locale's path, or none
     | of them.
     |
     | A row inserted -- a create, or a locale's first -- gets every column
     | from the validated values, so a field left off it holds its declared
     | default, or what its type stores for nothing, rather than whatever the
     | column defaults to. A row
     | updated gets only the columns the call was given. The rest were read to
     | be validated, not to be written: written back, they would put a field
     | another save changed in the meantime back the way it was.
     */
    private function write(string $type, ?int $id, array $values, array $given, string $locale, bool $translated, bool $reads): int
    {
        $handle = $type::handle();
        $site = $this->site();
        [$localized, $shared] = $this->columns($type);
        $serialize = fn (array $fields) => array_map(fn (Field $field) => $field->encode($field->serialize($values[$field->name] ?? null)), $fields);
        $changed = fn (array $fields) => array_filter($fields, fn (Field $field) => array_key_exists($field->name, $given));
        $routed = is_subclass_of($type, Entry::class);
        /* Blank is no view of its own, which is how one is taken off. */
        $template = $routed && array_key_exists('template', $given) ? ['template' => blank($given['template']) ? null : $given['template']] : [];

        return DB::transaction(function () use ($type, $handle, $id, $site, $locale, $translated, $reads, $localized, $shared, $serialize, $changed, $template, $routed, $values, $given) {
            $created = $id === null;

            $now = $this->stamp(CarbonImmutable::now());

            if ($id === null) {
                $id = DB::table($handle)->insertGetId(['site_id' => $site, ...$serialize($shared), ...$template, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                /* Only while it is out of the trash, and asked again after. A
                   delete committed since the load leaves the update nothing
                   to match, and writing on would give a trashed entry its
                   paths back. The update holds the row from here, so a delete
                   arriving later waits for this commit. Asked with a locking
                   read: inside a caller's own transaction MySQL answers a
                   plain one from the snapshot taken before the delete. */
                DB::table($handle)->where('id', $id)->whereNull('deleted_at')->update([...$serialize($changed($shared)), ...$template, 'updated_at' => $now]);

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

            $this->terms($type, (int) $id, $values, $given, $created, $this->snapshotted(1));

            if ($routed) {
                $this->paths($type, (int) $id, $site, $created, $reads, $this->snapshotted(1));
            }

            return (int) $id;
        }, self::ATTEMPTS);
    }

    /*
     | Each Terms field's rows in its taxonomy's pivot, as the save leaves the
     | field: a term taken off deleted, one added inserted, one moved given its
     | new place, and the rest not touched. Only the fields the call gave, or
     | every one on a new entry. The upsert settles an insert racing another
     | save's for the same term. Where a plain read answers from a caller's
     | older snapshot -- `$nested`, see snapshotted() -- the rows held are read
     | with a lock, as paths() reads its own.
     */
    private function terms(string $type, int $id, array $values, array $given, bool $created, bool $nested): void
    {
        $handle = $type::handle();

        foreach ($this->mainstay->fields($type) as $name => $field) {
            if (! $field instanceof Terms || ! ($created || array_key_exists($name, $given))) {
                continue;
            }

            $table = $field->of::handle().'_entries';
            $wanted = array_map(fn (mixed $term) => (int) $term, array_values($values[$name] ?? []));
            $held = $created ? [] : DB::table($table)->where('entry_type', $handle)->where('entry_id', $id)
                ->when($nested, fn (Builder $query) => $query->lockForUpdate())
                ->pluck('position', 'term_id')
                ->mapWithKeys(fn (mixed $position, mixed $term) => [(int) $term => (int) $position])
                ->all();

            if (($gone = array_diff(array_keys($held), $wanted)) !== []) {
                DB::table($table)->where('entry_type', $handle)->where('entry_id', $id)->whereIn('term_id', array_values($gone))->delete();
            }

            $rows = [];

            foreach ($wanted as $position => $term) {
                if (($held[$term] ?? null) !== $position) {
                    $rows[] = ['term_id' => $term, 'entry_type' => $handle, 'entry_id' => $id, 'position' => $position];
                }
            }

            if ($rows !== []) {
                DB::table($table)->upsert($rows, ['term_id', 'entry_type', 'entry_id'], ['position']);
            }
        }
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
     |
     | The refusal names the path only to a caller that may read the type. A
     | path spells out the fields it is built from, stored ones the call did
     | not give and other locales' among them, which is why a write hands
     | such a caller back no path either.
     |
     | Where a plain read answers from a caller's older snapshot -- `$nested`,
     | see snapshotted() -- what paths are built from is read with a lock, so
     | a slug another request has changed since does not build the path from
     | the old one, and a path it added is not missed. Anywhere else the reads
     | stay plain and take no gap locks to deadlock on.
     */
    private function paths(string $type, int $id, int $site, bool $created, bool $reads, bool $nested): void
    {
        $handle = $type::handle();
        $wanted = $this->wanted($type, $id, $reads, $nested);
        $rows = $created ? collect() : DB::table('uris')->where('type', $handle)->where('entry_id', $id)
            ->when($nested, fn (Builder $query) => $query->lockForUpdate())
            ->get(['id', 'locale', 'uri']);
        $held = $rows->keyBy('locale')->only(array_keys($wanted));

        /* The row of a locale whose path went: one the config dropped, or a
           route the type no longer has. */
        if (($gone = $rows->pluck('id')->diff($held->pluck('id')))->isNotEmpty()) {
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
                throw ValidationException::withMessages(array_fill_keys($this->blamed($type), $reads
                    ? "The path {$uri} is already taken in {$locale}."
                    : 'The path this builds is already taken.'));
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
    private function wanted(string $type, int $id, bool $reads, bool $nested): array
    {
        if (($patterns = $this->patterns($type)) === null) {
            return [];
        }

        $handle = $type::handle();
        $row = DB::table($handle)->where('id', $id)->when($nested, fn (Builder $query) => $query->lockForUpdate())->first();
        $wanted = [];

        foreach (DB::table("{$handle}_locales")->where('parent_id', $id)->when($nested, fn (Builder $query) => $query->lockForUpdate())->get() as $translation) {
            if (! isset($patterns[$translation->locale])) {
                continue;
            }

            $uri = $this->built($type, $patterns[$translation->locale], $row, $translation);

            /* The column's width, which the drivers other than SQLite enforce
               with an error nobody reading a form could act on. */
            if (mb_strlen($uri) > 255) {
                throw ValidationException::withMessages(array_fill_keys($this->blamed($type), $reads
                    ? "The path {$uri} is longer than the 255 characters a path can be."
                    : 'The path this builds is longer than the 255 characters a path can be.'));
            }

            /* Refused as a taken path is: a visitor asking for it would be
               answered by something else, and the entry never. */
            if (($shadow = $this->mainstay->shadow($translation->locale, $uri)) !== null) {
                throw ValidationException::withMessages(array_fill_keys($this->blamed($type), $reads
                    ? "The path {$uri} is answered by {$shadow} in {$translation->locale}."
                    : 'The path this builds is answered by something else.'));
            }

            $wanted[$translation->locale] = $uri;
        }

        return $wanted;
    }

    /* A locale's path from its pattern, each placeholder the field's value
       on the row or its locale's, or the one `$with` gives it instead. */
    private function built(string $type, string $pattern, object $row, object $translation, array $with = []): string
    {
        $fields = $this->mainstay->fields($type);

        return preg_replace_callback(
            '/'.Route::PLACEHOLDER.'/',
            fn (array $name) => $with[$name[1]] ?? ($fields[$name[1]]->localized ? $translation : $row)->{Str::snake($name[1])},
            $pattern,
        );
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
        $locales = array_keys($this->mainstay->locales());

        if ($route === null) {
            return null;
        }

        $patterns = is_array($route) ? $route : array_fill_keys($locales, $route);

        if (array_diff(array_keys($patterns), $locales) !== [] || array_diff($locales, array_keys($patterns)) !== []) {
            throw new InvalidArgumentException(sprintf(
                "%s's #[Route] has patterns for %s, and mainstay.locales holds %s. Give a pattern for every content locale, or one pattern for all of them.",
                $type,
                implode(', ', array_keys($route)),
                implode(', ', $locales),
            ));
        }

        /* A pattern every entry would be refused for, named here, once,
           rather than as a slug that cannot be changed to anything that
           works. Asked with the pattern as written: a `{slug}` is no locale's
           prefix and no route's literal segment, so only what answers any
           value there answers it. */
        foreach ($patterns as $locale => $pattern) {
            if (($shadow = $this->mainstay->shadow($locale, $pattern)) !== null) {
                throw new InvalidArgumentException("{$type}'s #[Route] pattern \"{$pattern}\" puts every {$locale} path under {$shadow}, which answers it instead.");
            }
        }

        return $patterns;
    }

    /* The fields a path is built from, in any locale's pattern. */
    private function placeholders(string $type): array
    {
        preg_match_all('/'.Route::PLACEHOLDER.'/', implode(' ', $this->patterns($type) ?? []), $names);

        return array_values(array_unique($names[1]));
    }

    /*
     | A row as the declared class. `$locale` is null for the entry a write
     | asks Gate about, which is the main row alone -- its owner, its shared
     | fields and its terms, what a policy decides on -- so its locale, its
     | path and its localized fields are not set, and nothing loads what it
     | points at, which reads as missing.
     */
    private function hydrate(string $type, object $row, ?string $locale, bool $internal, ?array $only = null, array $loaded = [], bool $lenient = false): ContentType
    {
        [$class, $properties] = $this->reflection($type);
        $entry = $class->newInstanceWithoutConstructor();

        /*
         | Every field starts absent, and only what was read is set. Absent
         | rather than null, since null is a value a field holds, and a template
         | printing a note it was not given should fail where it reads it rather
         | than print nothing. A default the declaration gave would be a value
         | nobody stored -- an internal note for a reader who may not see it, a
         | localized field on the entry Gate is shown, which the main row does
         | not carry -- so it goes too.
         */
        foreach ($properties as $property) {
            $this->absent($entry, $property);
        }

        /* None, for an entry not published yet, which a draft reads. */
        if ($row->id !== null) {
            $entry->id = (int) $row->id;
        }

        /* A caller that may not read the type is handed the id and nothing it
           did not write: not the owner, not the stamps, and not the path,
           which spells out the fields it is built from. */
        if ($only === null) {
            $entry->ownerId = $row->owner_id === null ? null : (int) $row->owner_id;
            $entry->createdAt = blank($row->created_at) ? null : $this->moment->cast($row->created_at);
            $entry->updatedAt = blank($row->updated_at) ? null : $this->moment->cast($row->updated_at);
        }

        $routed = $entry instanceof Entry;

        if ($routed && ($only === null || in_array('template', $only, true))) {
            (new ReflectionProperty(Entry::class, 'template'))->setValue($entry, $row->template);
        }

        if ($locale !== null) {
            $entry->locale = $locale;

            if ($routed && $only === null) {
                $entry->uri = $row->uri;
            }
        }

        foreach ($this->mainstay->fields($type) as $name => $field) {
            $column = Str::snake($name);

            if (! property_exists($row, $column) || ($field->internal && ! $internal) || ($only !== null && ! in_array($name, $only, true))) {
                continue;
            }

            /* Through reflection, which initializes a readonly property from
               outside its class where assignment cannot.

               The entry a write asks Gate about is built before Gate has
               answered, so a stored value its field cannot read -- a blank
               select, put there from outside the layer -- is left out of it,
               rather than refusing the caller in that field's name. A read
               still fails on it, naming the declaration. So is a draft's
               field left empty that its property cannot hold, `$lenient`. */
            try {
                $properties[$name]->setValue($entry, $field->cast($this->decode($type, $field, $row->{$column}), $loaded));
            } catch (InvalidArgumentException $exception) {
                if ($locale !== null && ! $lenient) {
                    throw $exception;
                }
            }
        }

        return $entry;
    }

    /*
     | The class and its fields' properties, reflected once per type for as
     | long as this store lives -- one call -- so a find of a hundred rows
     | builds one of each rather than one per row.
     |
     | @return array{0: ReflectionClass, 1: array<string, ReflectionProperty>}
     */
    private function reflection(string $type): array
    {
        return $this->reflected[$type] ??= [
            new ReflectionClass($type),
            array_map(fn (Field $field) => new ReflectionProperty($type, $field->name), $this->mainstay->fields($type)),
        ];
    }

    /* Unset from the declaring class, where a `protected(set)` property
       allows it; a readonly one has no default to take away. A hooked one
       cannot be unset at all, so one the caller is not handed keeps the
       default its declaration gave: declared rather than stored, so nothing
       read leaks through it. */
    private function absent(ContentType $entry, ReflectionProperty $property): void
    {
        if ($property->isInitialized($entry) && ! (method_exists($property, 'hasHooks') && $property->hasHooks())) {
            $name = $property->getName();

            Closure::bind(function () use ($name) {
                unset($this->{$name});
            }, $entry, $property->class)();
        }
    }
}
