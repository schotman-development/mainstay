<?php

namespace Mainstay\Http;

use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\Gate;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Mainstay;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/*
 | The accounts' screens over Mainstay::accounts(), for whoever may manage
 | them: the list, a new account invited by mail, an account's own page.
 | And Account settings, everyone's own. The layer asks AccountPolicy on
 | every write, so a refusal is its own; a screen only leaves out what the
 | user may not do.
 */
class UserController
{
    public function __construct(private Mainstay $mainstay) {}

    public function index(): View
    {
        $this->sees();

        return view('mainstay::users.index', ['users' => User::query()->with('role')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        $this->sees();

        return view('mainstay::users.form', ['account' => null, 'roles' => $this->offered(), 'changes' => true, 'deletes' => false]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->sees();
        $user = $this->mainstay->accounts()->create((string) $request->input('name'), (string) $request->input('email'), (int) $request->input('role'));

        return $this->invited($user->id, 'Account made, and an invitation mailed to choose its password.');
    }

    /* The user's own account is changed in Account settings, which asks for
       the password before the email changes. */
    public function edit(int $id): View|RedirectResponse
    {
        $this->sees();

        if ($id === Gate::user()->getKey()) {
            return redirect()->route('mainstay.account');
        }

        $account = User::query()->with('role')->find($id) ?? throw $this->missing();

        return view('mainstay::users.form', [
            'account' => $account,
            'roles' => $this->offered(),
            'changes' => Gate::accounts()->allows('update', [$account]),
            'deletes' => Gate::accounts()->allows('delete', [$account]),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->sees();
        $this->found(fn () => $this->mainstay->accounts()->update($id, name: (string) $request->input('name'), email: (string) $request->input('email'), role: (int) $request->input('role')));

        return redirect()->route('mainstay.users.edit', $id)->with('status', 'Saved.');
    }

    public function invite(int $id): RedirectResponse
    {
        $this->sees();

        return $this->invited($id, 'Invitation mailed.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->sees();
        $this->found(fn () => $this->mainstay->accounts()->delete($id));

        return redirect()->route('mainstay.users')->with('status', 'Account deleted.');
    }

    public function account(): View
    {
        return view('mainstay::account', ['account' => Gate::user()]);
    }

    /*
     | The user's own name, email and password. A new password ends every
     | other session: the layer rotates the remember token, and the guard
     | sends this device a cookie with the new one, so it stays signed in.
     */
    public function saveAccount(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['nullable', 'confirmed']]);
        $me = Gate::user();
        $password = $request->filled('password') ? (string) $request->input('password') : null;

        $this->mainstay->accounts()->update($me->getKey(), name: (string) $request->input('name'), email: (string) $request->input('email'), password: $password, current: $request->input('current_password'));

        /* The guard's user is this one, read before the layer wrote. */
        if ($password !== null) {
            $me->refresh();
            Auth::guard('mainstay')->logoutOtherDevices($password);
        }

        return redirect()->route('mainstay.account')->with('status', 'Saved.');
    }

    /* After the account is written: a mailer that fails leaves it made, and
       its page says so beside "Send the invitation again". */
    private function invited(int $id, string $status): RedirectResponse
    {
        $page = redirect()->route('mainstay.users.edit', $id);

        try {
            $this->found(fn () => $this->mainstay->accounts()->invite($id));
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return $page->withErrors(['invite' => "The invitation could not be mailed: {$exception->getMessage()}"]);
        }

        return $page->with('status', $status);
    }

    /* The roles the user could give an account: those they cover. */
    private function offered(): Collection
    {
        return Role::query()->orderBy('name')->get()->filter(fn (Role $role) => Capabilities::uncovered(Gate::user(), $role->capabilities) === null)->values();
    }

    /* The accounts' screens are the admin's not-found to anyone who may not
       manage accounts, as a type's are to anyone who may not write it. */
    private function sees(): void
    {
        if (Gate::accounts()->denies('viewAny', User::class)) {
            throw $this->missing();
        }
    }

    private function found(callable $write): void
    {
        try {
            $write();
        } catch (RecordNotFoundException) {
            throw $this->missing();
        }
    }

    private function missing(): HttpResponseException
    {
        return new HttpResponseException(response()->view('mainstay::missing', status: 404));
    }
}
