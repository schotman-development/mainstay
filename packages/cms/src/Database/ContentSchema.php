<?php

namespace Mainstay\Database;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mainstay\Fields\Field;
use Mainstay\Mainstay;

/*
 | The field list as tables, the live database compared against them, and the
 | alterations that close the gap. What mainstay:sync applies and what
 | mainstay:schema:check reports are the same diff, so the check cannot pass on
 | a database sync would still change.
 */
class ContentSchema
{
    /* The migration name sync records itself under, with batch -1 -- Payload's
       marker for a database that was pushed rather than migrated. */
    public const MARKER = 'mainstay:sync';

    public function __construct(private Mainstay $mainstay) {}

    /*
     | Two tables per type, always: the main row, and a `_locales` sibling for
     | the localized fields. The sibling exists even with nothing localized
     | yet, because a locale with no row is how an untranslated entry is told
     | apart from a translated one.
     |
     | `columns` and `keys` are separate because the comparison builds
     | `columns` under a scratch name, where a foreign key would reference a
     | table that may not exist yet. Keys are written when a table is created
     | and not compared. `fields` is keyed by column name, for sync to look a
     | column's field up by.
     |
     | @return array<string, array{columns: Closure(Blueprint): void, keys: Closure(Blueprint): void, fields: array<string, Field>}>
     */
    public function tables(): array
    {
        $tables = [];

        foreach ($this->mainstay->registered() as $handle => $type) {
            $fields = $this->mainstay->fields($type);
            $columns = collect($fields)->keyBy(fn (Field $field) => Str::snake($field->name));

            /*
             | Refused here, which both commands pass through before touching
             | anything. Each of these otherwise surfaces partway through a
             | sync: a duplicate column after the main table was created, one
             | of two properties silently losing its column, or a diff that
             | drops the columns of a table that was never a content type's.
             */
            if ($columns->count() !== count($fields)) {
                throw new InvalidArgumentException("{$type} has two properties stored in the same column. Rename one of them.");
            }

            foreach (['id', 'site_id', 'parent_id', 'locale', 'deleted_at'] as $reserved) {
                if ($columns->has($reserved)) {
                    throw new InvalidArgumentException("{$type} has a field stored as {$reserved}, a column Mainstay keeps for itself. Rename the property.");
                }
            }

            if (in_array($handle, ['sites', 'uris', 'migrations'], true) || str_ends_with($handle, '_locales')) {
                throw new InvalidArgumentException("{$type} would be stored in {$handle}, a table Mainstay keeps for itself. Rename the class.");
            }

            [$localized, $shared] = $columns->partition(fn (Field $field) => $field->localized)->map->all();

            $tables[$handle] = [
                'columns' => function (Blueprint $table) use ($shared) {
                    $table->id();
                    $table->unsignedBigInteger('site_id');
                    $this->fields($table, $shared);
                    $table->softDeletes();
                },
                'keys' => function (Blueprint $table) {
                    $table->foreign('site_id')->references('id')->on('sites');
                },
                'fields' => $shared,
            ];

            $tables["{$handle}_locales"] = [
                'columns' => function (Blueprint $table) use ($localized) {
                    $table->id();
                    $table->unsignedBigInteger('parent_id');
                    $table->unsignedBigInteger('site_id');
                    $table->string('locale');
                    $this->fields($table, $localized);
                    $table->softDeletes();
                },
                'keys' => function (Blueprint $table) use ($handle) {
                    $table->foreign('parent_id')->references('id')->on($handle);
                    $table->foreign('site_id')->references('id')->on('sites');
                    $table->unique(['parent_id', 'locale']);
                },
                'fields' => $localized,
            ];
        }

        return $tables;
    }

    /*
     | Per table, what differs: missing outright, or columns to add, to drop,
     | and to change from the live `[type, nullable]` to the declared one.
     | Tables that match are left out, so an empty array is a database in step.
     |
     | Types are compared as the driver reports them for a table Laravel built
     | from the declaration, rather than through a map of what each driver
     | calls each Blueprint method. That costs a CREATE and a DROP on the
     | connection being checked, and buys a comparison that works for any
     | column a host's field type asks for.
     |
     | @return array<string, array{missing: bool, add: list<string>, drop: list<string>, change: array<string, array{live: array, declared: array}>}>
     */
    public function diff(): array
    {
        $diff = [];

        foreach ($this->tables() as $name => $table) {
            if (! Schema::hasTable($name)) {
                $diff[$name] = ['missing' => true, 'add' => [], 'drop' => [], 'change' => []];

                continue;
            }

            $live = $this->columns($name);
            $declared = $this->scratch($table['columns']);

            $change = [];

            foreach (array_intersect_key($declared, $live) as $column => $shape) {
                if ($shape !== $live[$column]) {
                    $change[$column] = ['live' => $live[$column], 'declared' => $shape];
                }
            }

            $changes = [
                'missing' => false,
                'add' => array_keys(array_diff_key($declared, $live)),
                'drop' => array_keys(array_diff_key($live, $declared)),
                'change' => $change,
            ];

            if ($changes['add'] !== [] || $changes['drop'] !== [] || $changes['change'] !== []) {
                $diff[$name] = $changes;
            }
        }

        return $diff;
    }

    /* One line per difference, for both commands to print. */
    public function describe(array $diff): array
    {
        $lines = [];

        foreach ($diff as $table => $changes) {
            if ($changes['missing']) {
                $lines[] = "{$table}: declared, and the table does not exist";
            }

            foreach ($changes['add'] as $column) {
                $lines[] = "{$table}.{$column}: declared, and the column does not exist";
            }

            foreach ($changes['drop'] as $column) {
                $lines[] = "{$table}.{$column}: in the database, and not declared";
            }

            foreach ($changes['change'] as $column => ['live' => $live, 'declared' => $declared]) {
                $lines[] = "{$table}.{$column}: {$this->shape($live)} in the database, declared {$this->shape($declared)}";
            }
        }

        return $lines;
    }

    /*
     | Applies a diff. The caller decides whether the drops in it are wanted;
     | this does what it is handed. Tables are created in tables() order, which
     | puts each main table ahead of the sibling whose key references it.
     |
     | A required column cannot arrive NOT NULL on a table that exists: SQLite
     | refuses `ADD COLUMN ... NOT NULL` without a default even when the table
     | is empty, and Postgres refuses it once there are rows. Tightening a
     | nullable column fails on the rows holding null. So every column goes in
     | nullable and in its declared type, the nulls are filled, and only then
     | is it made required. Type before fill: a value written for the declared
     | type is not one Postgres or strict MySQL will store in the old type.
     |
     | Every fill value, for every table, is settled before the first ALTER,
     | so a column nothing can fill stops the sync with the schema as it was.
     | The marker migrate warns on is written between the two: after the last
     | refusal that leaves the schema as it was, before the first change.
     | Nothing else is checked ahead: a Postgres cast refusing stored values,
     | or a restored unique index refusing duplicate rows, fails where it
     | happens, after the tables ahead of it were altered.
     */
    public function sync(array $diff): void
    {
        $tables = $this->tables();
        $plans = [];

        foreach ($diff as $name => $changes) {
            if ($changes['missing']) {
                continue;
            }

            $definitions = $this->definitions($name, $tables[$name]['columns']);

            /* The primary key is left as it is. Made nullable on the way
               through, as every other column is, it is refused by MySQL and
               Postgres; a key of the wrong type stays in the check's report. */
            unset($changes['change']['id']);

            $required = array_values(array_filter(
                [...$changes['add'], ...array_keys($changes['change'])],
                fn (string $column) => ! $definitions[$column]->nullable,
            ));

            /* Only where a row needs one: an empty table takes a required
               column with no value at all. */
            $holdsRows = DB::table($name)->exists();
            $fills = [];

            foreach ($required as $column) {
                $needed = in_array($column, $changes['add'], true)
                    ? $holdsRows
                    : DB::table($name)->whereNull($column)->exists();

                if ($needed) {
                    $fills[$column] = $this->fill($name, $definitions[$column], $tables[$name]['fields'][$column] ?? null);
                }
            }

            $plans[$name] = compact('changes', 'definitions', 'required', 'fills');
        }

        /* Not when the plan leaves nothing to do: a diff of nothing but a
           primary key is one sync reports and does not touch, and a marker
           for it makes every later migrate warn about a database that was
           never pushed. */
        $changing = collect($diff)->contains(fn (array $changes) => $changes['missing'])
            || collect($plans)->contains(fn (array $plan) => $plan['changes']['add'] !== []
                || $plan['changes']['drop'] !== []
                || $plan['changes']['change'] !== []);

        if ($changing) {
            $this->mark();
        }

        foreach ($diff as $name => $changes) {
            if ($changes['missing']) {
                Schema::create($name, function (Blueprint $table) use ($tables, $name) {
                    $tables[$name]['columns']($table);
                    $tables[$name]['keys']($table);
                });

                continue;
            }

            ['changes' => $changes, 'definitions' => $definitions, 'required' => $required, 'fills' => $fills] = $plans[$name];

            /*
             | Postgres is altered in its own words rather than through
             | change(). change() restates the column as Blueprint builds it,
             | and for an enum -- a varchar with a check -- that restatement is
             | not valid in ALTER COLUMN. The declared type is the one the
             | scratch table reported, so it is already Postgres's spelling of
             | it, and a check constraint is not something the diff compares.
             |
             | Postgres converts stored values only when told how, so the type
             | change casts. Values that do not convert fail with Postgres's
             | error, and nothing is invented for them. Values that do convert
             | can still lose data -- a varchar cast cuts them short -- which
             | lossy() finds for mainstay:sync to ask about first.
             */
            $pgsql = Schema::getConnection()->getDriverName() === 'pgsql';
            $grammar = Schema::getConnection()->getQueryGrammar();

            if ($changes['add'] !== [] || (! $pgsql && $changes['change'] !== [])) {
                Schema::table($name, function (Blueprint $table) use ($changes, $definitions, $pgsql) {
                    foreach ($changes['add'] as $column) {
                        $table->addColumn($definitions[$column]->type, $column, ['nullable' => true] + $definitions[$column]->getAttributes());
                    }

                    foreach ($pgsql ? [] : array_keys($changes['change']) as $column) {
                        $table->addColumn($definitions[$column]->type, $column, ['nullable' => true] + $definitions[$column]->getAttributes())->change();
                    }
                });
            }

            if ($pgsql) {
                foreach ($changes['change'] as $column => ['live' => $live, 'declared' => $declared]) {
                    $wrapped = $grammar->wrap($column);

                    /* A cast rewrites the whole table under an exclusive lock,
                       so it is only asked for when the type actually differs. */
                    $type = $live['type'] === $declared['type'] ? '' : "alter column {$wrapped} type {$declared['type']} using {$wrapped}::{$declared['type']}, ";

                    DB::statement("alter table {$grammar->wrapTable($name)} {$type}alter column {$wrapped} drop not null");
                }
            }

            foreach ($fills as $column => $value) {
                DB::table($name)->whereNull($column)->update([$column => $value]);
            }

            /* Every required column already has its declared type, so what is
               left is NOT NULL alone -- which Postgres is told directly, for the
               same reason as above. */
            if ($pgsql) {
                foreach ($required as $column) {
                    DB::statement("alter table {$grammar->wrapTable($name)} alter column {$grammar->wrap($column)} set not null");
                }
            }

            Schema::table($name, function (Blueprint $table) use ($name, $changes, $definitions, $required, $pgsql) {
                if (! $pgsql) {
                    foreach ($required as $column) {
                        $table->addColumn($definitions[$column]->type, $column, $definitions[$column]->getAttributes())->change();
                    }
                }

                /* Keys are written with a table, so a key column added to one
                   that already exists brings its key with it. */
                if (in_array('site_id', $changes['add'], true)) {
                    $table->foreign('site_id')->references('id')->on('sites');
                }

                if (in_array('parent_id', $changes['add'], true)) {
                    $table->foreign('parent_id')->references('id')->on(Str::beforeLast($name, '_locales'));
                }

                if (array_intersect(['parent_id', 'locale'], $changes['add']) !== []) {
                    $table->unique(['parent_id', 'locale']);
                }

                if ($changes['drop'] !== []) {
                    $table->dropColumn($changes['drop']);
                }
            });
        }
    }

    /*
     | The retyped columns, as `table.column`, holding a value the declared
     | type would not give back unchanged: a string cut short, a float
     | rounded, a code with its leading zeros gone.
     |
     | Asked of the database rather than of the type names, because whether
     | a conversion loses anything depends on the driver and on what is
     | stored. The values are copied into a scratch table of the declared
     | types the way sync converts them -- Postgres's cast, and plain
     | assignment elsewhere -- and compared as text with what they came from.
     | A different spelling of the same value, a decimal's trailing zero,
     | reads as a loss; that asks once too often rather than once too few.
     */
    public function lossy(array $diff): array
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        $grammar = $connection->getQueryGrammar();
        $tables = $this->tables();
        $lossy = [];

        foreach ($diff as $name => $changes) {
            /* The primary key is left out, since sync leaves it alone. */
            $retyped = collect($changes['change'])
                ->except('id')
                ->filter(fn (array $shapes) => $shapes['live']['type'] !== $shapes['declared']['type']);

            if ($retyped->isEmpty() || ! DB::table($name)->exists()) {
                continue;
            }

            /* Settled before the scratch table is built, so a driver with no
               words of its own is refused before anything is made for it. */
            [$text, $differs] = $this->comparison($driver);

            $definitions = $this->definitions($name, $tables[$name]['columns']);
            $scratch = 'mainstay_scratch_'.bin2hex(random_bytes(4));

            try {
                /* The key as text, so a table whose id is not an integer
                   still pairs its rows. */
                Schema::create($scratch, function (Blueprint $table) use ($retyped, $definitions) {
                    $table->string('id')->primary();

                    foreach ($retyped->keys() as $column) {
                        $table->addColumn($definitions[$column]->type, $column, ['nullable' => true] + $definitions[$column]->getAttributes());
                    }
                });

                $columns = $retyped->keys()->map(fn (string $column) => $grammar->wrap($column));
                $values = $retyped->map(fn (array $shapes, string $column) => $driver === 'pgsql'
                    ? "{$grammar->wrap($column)}::{$shapes['declared']['type']}"
                    : $grammar->wrap($column));

                /* Refused outright -- a Postgres cast a value does not parse
                   in, or strict MySQL declining to cut a string -- sync would
                   be refused the same way, after the tables ahead of it. */
                try {
                    DB::statement("insert into {$grammar->wrapTable($scratch)} (id, {$columns->implode(', ')}) select cast(id as {$text}), {$values->implode(', ')} from {$grammar->wrapTable($name)}");
                } catch (QueryException $e) {
                    throw new InvalidArgumentException("{$name} holds values the declared type of ".$retyped->keys()->map(fn (string $column) => "{$name}.{$column}")->implode(', ').' refuses. Change them by hand first.', previous: $e);
                }

                foreach ($retyped as $column => $shapes) {
                    [$live, $converted] = ["l.{$grammar->wrap($column)}", "s.{$grammar->wrap($column)}"];

                    /* Asked through the builder rather than in one string, so
                       the one row is taken the way each driver spells it:
                       SQL Server has no LIMIT and wants TOP instead, and the
                       grammar already knows that. */
                    $lost = DB::table("{$name} as l")
                        ->join("{$scratch} as s", 's.id', '=', DB::raw("cast(l.id as {$text})"))
                        ->whereRaw($differs($live, $converted, $shapes['declared']['type']))
                        ->exists();

                    if ($lost) {
                        $lossy[] = "{$name}.{$column}";
                    }
                }
            } finally {
                Schema::dropIfExists($scratch);
            }
        }

        return $lossy;
    }

    /*
     | What this driver casts a key to so the two tables pair, and how it is
     | asked whether a stored value and its converted self differ. One arm per
     | driver Laravel connects to, because neither answer is portable.
     |
     | MySQL as bytes, since its collations can ignore trailing spaces and
     | letter case. SQLite keeps a number as an integer or a real by what it
     | holds, so 42 and 42.0 are the same value there. Postgres says it
     | outright. SQL Server has none of their words: no IS DISTINCT FROM
     | before 2022, no text it will compare, and a default collation that
     | ignores case -- so it asks INTERSECT, which pairs two nulls as equal
     | the way the others do, over a binary collation that does not.
     |
     | The declared type is passed because SQL Server alone needs it: CAST
     | renders a float at six significant digits, so two values a retype did
     | round apart both read back as 1.23457 and the question never gets
     | asked. CONVERT with style 3 renders the seventeen that come back the
     | same float, which is the whole point of the comparison. Style 3 wants
     | SQL Server 2016; nothing older is contemplated here.
     */
    private function comparison(string $driver): array
    {
        $binary = 'collate Latin1_General_BIN2';
        $sqlsrv = fn (string $value, string $type) => str_contains($type, 'float') || str_contains($type, 'real')
            ? "convert(varchar(max), {$value}, 3)"
            : "cast({$value} as varchar(max)) {$binary}";

        return match ($driver) {
            'mysql', 'mariadb' => ['char', fn (string $live, string $converted, string $type) => "not (cast({$live} as binary) <=> cast({$converted} as binary))"],
            'sqlite' => ['text', fn (string $live, string $converted, string $type) => "cast({$live} as text) is not cast({$converted} as text) and not (typeof({$live}) in ('integer', 'real') and typeof({$converted}) in ('integer', 'real') and {$live} = {$converted})"],
            'pgsql' => ['text', fn (string $live, string $converted, string $type) => "cast({$live} as text) is distinct from cast({$converted} as text)"],
            'sqlsrv' => ['varchar(max)', fn (string $live, string $converted, string $type) => "not exists (select {$sqlsrv($live, $type)} intersect select {$sqlsrv($converted, $type)})"],
            default => throw new InvalidArgumentException("mainstay:sync has no way to tell whether a type change loses values on {$driver}. Change the columns by hand, or sync on sqlite, mysql, mariadb, pgsql or sqlsrv."),
        };
    }

    /* The declared definitions without executing anything: a Blueprint runs
       its closure when it is constructed and only touches the database when
       it is built. */
    private function definitions(string $table, Closure $columns): Collection
    {
        return collect((new Blueprint(Schema::getConnection(), $table, $columns))->getAddedColumns())->keyBy('name');
    }

    /* Bound by name only; the interface is not in the container. */
    private function mark(): void
    {
        $migrations = app('migration.repository');

        if (! in_array(self::MARKER, $migrations->getRan(), true)) {
            $migrations->log(self::MARKER, -1);
        }
    }

    /*
     | The value a required column gives the rows that have none.
     |
     | A field answers first. One that has nothing to say -- a host's type with
     | no empty value, which Field::backfill() cannot invent one for -- gets the
     | plain zero of its column's type, because the column has to be added
     | whatever the table holds. Of Mainstay's own columns, a row with no site
     | belongs to the first one and a row with no locale is in the default --
     | the only readings a single-site, single-locale install has. A missing
     | parent has no reading at all.
     */
    private function fill(string $table, ColumnDefinition $column, ?Field $field): mixed
    {
        if ($field !== null) {
            try {
                return $field->backfill();
            } catch (InvalidArgumentException) {
                /* No empty value of its own; the column's type answers. */
            }
        }

        $now = CarbonImmutable::now('UTC');

        return match (true) {
            $column->name === 'site_id' => DB::table('sites')->orderBy('id')->value('id')
                ?? throw new InvalidArgumentException("{$table}.site_id is required, and there is no site to give the rows already in the table. Run php artisan migrate."),
            $column->name === 'locale' => config('app.locale'),
            $column->name === 'parent_id' => throw new InvalidArgumentException("{$table}.parent_id is required, and a row with no parent has none to be given. Empty the table, or add the column by hand."),
            in_array($column->type, ['char', 'string', 'tinyText', 'text', 'mediumText', 'longText'], true) => '',
            in_array($column->type, ['tinyInteger', 'smallInteger', 'mediumInteger', 'integer', 'bigInteger', 'float', 'double', 'decimal'], true) => 0,
            $column->type === 'boolean' => false,
            $column->type === 'date' => $now->format('Y-m-d'),
            in_array($column->type, ['dateTime', 'dateTimeTz', 'timestamp', 'timestampTz'], true) => $now->format('Y-m-d H:i:s'),
            $column->type === 'time' => '00:00:00',
            $column->type === 'year' => (int) $now->format('Y'),
            in_array($column->type, ['enum', 'set'], true) => $column->allowed[0],
            in_array($column->type, ['json', 'jsonb'], true) => '[]',
            $column->type === 'uuid' => (string) Str::uuid(),
            $column->type === 'ulid' => (string) Str::ulid(),
            default => throw new InvalidArgumentException("{$table}.{$column->name} is required, and a {$column->type} column has no default to give the rows already in the table. Give the field type a backfill()."),
        };
    }

    /*
     | Columns are the snake_case of the property, the way every Laravel table
     | is written. A field that lives in JSON has no column of its own; the
     | JSON column arrives with the first field type that needs it, in phase 7.
     */
    private function fields(Blueprint $table, array $fields): void
    {
        foreach ($fields as $name => $field) {
            if (($column = $field->column()) === null) {
                continue;
            }

            [$method, $arguments] = [array_shift($column), $column];

            $table->{$method}($name, ...$arguments)->nullable($field->nullable);
        }
    }

    /* Under a random name, so two checks against one database do not collide. */
    private function scratch(Closure $columns): array
    {
        $name = 'mainstay_scratch_'.bin2hex(random_bytes(4));

        try {
            Schema::create($name, $columns);

            return $this->columns($name);
        } finally {
            Schema::dropIfExists($name);
        }
    }

    /* @return array<string, array{type: string, nullable: bool}> */
    private function columns(string $table): array
    {
        return collect(Schema::getColumns($table))
            ->mapWithKeys(fn (array $column) => [$column['name'] => ['type' => $column['type'], 'nullable' => $column['nullable']]])
            ->all();
    }

    private function shape(array $column): string
    {
        return $column['type'].($column['nullable'] ? ', nullable' : ', not null');
    }
}
