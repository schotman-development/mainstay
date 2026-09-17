<?php

namespace Mainstay\Database;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
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

            /* The declared definitions without executing anything: a Blueprint
               runs its closure when it is constructed and only touches the
               database when it is built. */
            $definitions = collect((new Blueprint(Schema::getConnection(), $name, $tables[$name]['columns']))->getAddedColumns())
                ->keyBy('name');

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
             | error, and nothing is invented for them.
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
