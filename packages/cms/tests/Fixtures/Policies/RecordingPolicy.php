<?php

namespace Mainstay\Tests\Fixtures\Policies;

/* A public form's policy that writes down each entry it is asked to update:
   what Gate is shown. */
class RecordingPolicy extends FormPolicy
{
    /** @var list<object> */
    public static array $asked = [];

    public function update(?object $user, object $entry): bool
    {
        self::$asked[] = $entry;

        return parent::update($user, $entry);
    }
}
