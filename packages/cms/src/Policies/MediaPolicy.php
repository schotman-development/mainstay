<?php

namespace Mainstay\Policies;

use Illuminate\Auth\Access\Response;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\User;
use Mainstay\Content\Media;

/*
 | What the media library lets a reader and a writer do, asked on every call
 | the library takes. One library, so its capabilities have no per-type
 | form: `upload_media` for every write, and `edit_others_media` beside it
 | for an image someone else uploaded, or nobody did.
 */
class MediaPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function create(?User $user): Response
    {
        return $this->needs($user, null);
    }

    public function update(?User $user, Media $media): Response
    {
        return $this->needs($user, $media);
    }

    public function delete(?User $user, Media $media): Response
    {
        return $this->needs($user, $media);
    }

    public function restore(?User $user, Media $media): Response
    {
        return $this->needs($user, $media);
    }

    public function forceDelete(?User $user, Media $media): Response
    {
        return $this->needs($user, $media);
    }

    private function needs(?User $user, ?Media $media): Response
    {
        $others = $media !== null && ($user === null || $media->ownerId !== $user->getKey());

        return Capabilities::check(
            $user,
            'Writing to the media library needs a Mainstay user, and there is none. Code that writes on its own authority passes overrideAccess: true.',
            ['upload_media'],
            ...($others ? [['edit_others_media']] : []),
        );
    }
}
