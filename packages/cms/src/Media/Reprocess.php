<?php

namespace Mainstay\Media;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Mainstay\Mainstay;

/* One image's copies that are not written yet. An image trashed since it was
   queued is left alone. */
class Reprocess implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $id) {}

    public function handle(Mainstay $mainstay): void
    {
        $mainstay->media()->reprocess($this->id);
    }
}
