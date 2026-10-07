<?php

namespace Mainstay\Auth;

use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\Taxonomy;
use Mainstay\Policies\EntryPolicy;
use Mainstay\Policies\GlobalPolicy;
use Mainstay\Policies\TermPolicy;

/*
 | Laravel's Gate, asked about Mainstay's user and never the default guard's:
 | on a host with members of its own, that would be a site visitor answering
 | Mainstay's policies.
 |
 | Built from the host's policies and default denial, and nothing else. Not
 | forUser(), which copies the host's before and after callbacks: Laravel
 | calls those with any user that is not null, and a host's, typed for its
 | own users, would be handed Mainstay's and throw. No guessing a policy by
 | name either: Laravel would match App\Policies\PostPolicy to any class
 | called Post, and a host's Eloquent Post and a content type called Post are
 | different things.
 */
class Gate extends AccessGate
{
    /* Where the admin's middleware puts the signed-in user on the request. */
    public const USER = 'mainstay.user';

    /*
     | The policy the host chose for a class -- Gate::policy() on it,
     | #[UsePolicy] on it, Gate::policy() on a class or interface it extends --
     | and `$policy` where it chose none. Built on every call, so a policy the
     | host registers later answers.
     */
    public static function for(string $class, string $policy): self
    {
        /** @var AccessGate $host */
        $host = app(GateContract::class);
        $user = self::user();
        $gate = new self(app(), fn () => $user, policies: $host->policies(), guessPolicyNamesUsingCallback: fn () => []);
        $gate->defaultDenialResponse = $host->defaultDenialResponse;

        return $gate->getPolicyFor($class) === null ? $gate->policy($class, $policy) : $gate;
    }

    /*
     | The gate for a content type, with EntryPolicy answering for it unless
     | the host chose another -- GlobalPolicy for a global, TermPolicy for a
     | taxonomy. One policy answers for every type of a shape, so a question
     | about a type rather than an entry hands the type over as well: Gate
     | takes the first of `[$type, $type]` to find the policy and drops it.
     */
    public static function about(string $type): self
    {
        return self::for($type, match (true) {
            is_subclass_of($type, GlobalSet::class) => GlobalPolicy::class,
            is_subclass_of($type, Taxonomy::class) => TermPolicy::class,
            default => EntryPolicy::class,
        });
    }

    /*
     | The user signed in to the admin, on a request that came through its
     | middleware, and nobody on any other: a public page, the API and the
     | console read as a visitor whoever is signed in. The guard is not asked,
     | since it would sign the editor in on a public page from the remember
     | cookie, which is sent on every path.
     */
    public static function user(): ?User
    {
        return app('request')->attributes->get(self::USER);
    }
}
