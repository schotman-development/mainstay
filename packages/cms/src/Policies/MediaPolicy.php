<?php

namespace Mainstay\Policies;

use Illuminate\Auth\Access\Response;
use Mainstay\Content\Media;

/*
 | What the media library lets a reader and a writer do, asked on every call
 | the library takes. As EntryPolicy: there is no Mainstay user until phase
 | 9, so reading is open and nobody writes, and code writing on its own
 | authority passes `overrideAccess: true`, which never reaches here.
 */
class MediaPolicy
{
    public function viewAny(?object $user): bool
    {
        return true;
    }

    public function create(?object $user): Response
    {
        return $this->nobody();
    }

    public function update(?object $user, Media $media): Response
    {
        return $this->nobody();
    }

    public function delete(?object $user, Media $media): Response
    {
        return $this->nobody();
    }

    private function nobody(): Response
    {
        return Response::deny('Writing to the media library needs a Mainstay user, and there is none. Code that writes on its own authority passes overrideAccess: true.');
    }
}
