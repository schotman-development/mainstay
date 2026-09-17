<?php

namespace Mainstay\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Mainstay\Database\ContentSchema;

class SyncCommand extends Command
{
    protected $signature = 'mainstay:sync {--force : Drop columns without asking}';

    protected $description = 'Alter the database to match the declared content types (development only)';

    public function handle(ContentSchema $schema): int
    {
        /* Before the flag, and not overridable by --force: a flag copied into a
           production .env by accident is exactly what this refuses. */
        if ($this->laravel->isProduction()) {
            $this->components->error('mainstay:sync does not run in production. Write a migration, and run mainstay:schema:check against it.');

            return self::FAILURE;
        }

        if (! config('mainstay.schema.sync')) {
            $this->components->error('Schema sync is off. Set MAINSTAY_SCHEMA_SYNC=true in development to turn it on.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('sites')) {
            $this->components->error('Every content table references sites, which does not exist yet. Run php artisan migrate first.');

            return self::FAILURE;
        }

        $diff = $schema->diff();

        if ($diff === []) {
            $this->components->info('The database already matches the declared content types.');

            return self::SUCCESS;
        }

        $this->components->bulletList($schema->describe($diff));

        /*
         | A renamed property reads as a dropped column and an added one, and
         | nothing in a diff can tell the two apart. Payload stops for the same
         | reason. Declining changes nothing at all, rather than applying the
         | half of the diff that does not drop, because the other half is
         | usually the new name the data was meant to move to.
         */
        $drops = collect($diff)->flatMap(fn (array $changes, string $table) => array_map(fn (string $column) => "{$table}.{$column}", $changes['drop']));

        if ($drops->isNotEmpty() && ! $this->option('force') && ! $this->confirm("Drop {$drops->implode(', ')}, and the data in them?")) {
            $this->components->warn('Nothing was changed.');

            return self::FAILURE;
        }

        $schema->sync($diff);

        /* Bound by name only; the interface is not in the container. */
        $migrations = $this->laravel->make('migration.repository');

        if (! in_array(ContentSchema::MARKER, $migrations->getRan(), true)) {
            $migrations->log(ContentSchema::MARKER, -1);
        }

        /* Asked again rather than assumed: sync leaves a primary key of the
           wrong type alone, and a difference it could not close is one the
           check will fail on. */
        if (($remaining = $schema->diff()) !== []) {
            $this->components->error('Sync could not close every difference. Change these by hand.');
            $this->components->bulletList($schema->describe($remaining));

            return self::FAILURE;
        }

        $this->components->info('The database matches the declared content types.');

        return self::SUCCESS;
    }
}
