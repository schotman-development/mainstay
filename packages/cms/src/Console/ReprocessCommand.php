<?php

namespace Mainstay\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mainstay\Media\Reprocess;

/*
 | One job per image out of the trash, each writing the copies the current
 | declarations name that are not there yet: how a size added to a field
 | reaches the images uploaded before it. On the queue, since a library is
 | not something to work through inside a request or a deploy.
 */
class ReprocessCommand extends Command
{
    protected $signature = 'mainstay:media:reprocess';

    protected $description = 'Queue the copies of every image that its fields declare and that are not written yet';

    public function handle(): int
    {
        $queued = 0;

        foreach (DB::table('mainstay_media')->whereNull('deleted_at')->select('id')->lazyById() as $row) {
            Bus::dispatch(new Reprocess((int) $row->id));
            $queued++;
        }

        $this->components->info("Queued {$queued} ".Str::plural('image', $queued).'.');

        return self::SUCCESS;
    }
}
