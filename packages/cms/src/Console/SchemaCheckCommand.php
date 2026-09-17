<?php

namespace Mainstay\Console;

use Illuminate\Console\Command;
use Mainstay\Database\ContentSchema;

/*
 | What CI runs against a database built by the hand-written migrations. It
 | changes nothing it keeps, but it is not read-only: each table is compared
 | against a scratch copy built from the declaration and dropped again.
 */
class SchemaCheckCommand extends Command
{
    protected $signature = 'mainstay:schema:check';

    protected $description = 'Compare the database against the declared content types, and fail if they differ';

    public function handle(ContentSchema $schema): int
    {
        $diff = $schema->diff();

        if ($diff === []) {
            $this->components->info('The database matches the declared content types.');

            return self::SUCCESS;
        }

        $this->components->error('The database does not match the declared content types.');
        $this->components->bulletList($schema->describe($diff));

        return self::FAILURE;
    }
}
