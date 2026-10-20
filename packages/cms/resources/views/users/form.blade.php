@php
    $title = $account?->name ?? 'New user';
    $invited = $account !== null && $account->password === null;
@endphp

{{--
 | A new account, or one already made: its name, email and role. Read only,
 | with no Save, for an account holding what the user does not, since
 | nobody manages what they could not hand out. Its own grants and denials
 | are listed, changing them being a matter for code.
--}}
<x-mainstay::admin
    :title="$title"
    :breadcrumb="[['label' => 'Users', 'href' => route('mainstay.users')], ['label' => $title]]"
>
    <x-slot:actions>
        @if ($deletes)
            <x-mainstay::dropdown align="end" :chevron="false" trigger-class="rounded-control p-1.5 text-muted hover:bg-surface hover:text-ink">
                <x-slot:label><x-mainstay::more-icon :title="$title" /></x-slot:label>
                <x-mainstay::dropdown-item danger data-dialog-open="delete-account">Delete</x-mainstay::dropdown-item>
            </x-mainstay::dropdown>
        @endif
        @if ($invited && $changes)
            <x-mainstay::button variant="secondary" type="submit" form="invite-form">Send the invitation again</x-mainstay::button>
        @endif
        @if ($changes)
            <x-mainstay::button type="submit" form="account-form">{{ $account === null ? 'Invite' : 'Save' }}</x-mainstay::button>
        @endif
    </x-slot:actions>

    <form id="account-form" method="post" action="{{ $account === null ? route('mainstay.users.store') : route('mainstay.users.update', $account->id) }}" data-dirty-form class="h-full overflow-y-auto">
        @csrf
        <fieldset @disabled(! $changes) class="mx-auto max-w-3xl space-y-6 px-6 py-8">
            <x-mainstay::notices :messages="$errors->all()" />

            @if ($invited)
                <p class="text-sm text-muted">Invited: no password chosen yet. The link in the invitation works for {{ config('auth.passwords.mainstay.expire', 60) }} minutes.</p>
            @endif

            <x-mainstay::field-group label="Account">
                <x-mainstay::field label="Name" for="account-name">
                    <x-mainstay::input id="account-name" name="name" maxlength="255" required :value="old('name', $account?->name)" />
                </x-mainstay::field>
                <x-mainstay::field label="Email" for="account-email" :hint="$account === null ? 'The invitation to choose a password goes here.' : null">
                    <x-mainstay::input id="account-email" name="email" type="email" maxlength="255" required :value="old('email', $account?->email)" :aria-describedby="$account === null ? 'account-email-hint' : null" />
                </x-mainstay::field>
                <x-mainstay::field label="Role" for="account-role">
                    <x-mainstay::select id="account-role" name="role" required>
                        @if ($account === null)
                            <option value="" disabled @selected(old('role') === null)>Choose a role</option>
                        @endif
                        @if ($account !== null && ! $roles->contains('id', $account->role_id))
                            <option value="{{ $account->role_id }}" selected>{{ ucfirst($account->role->name) }}</option>
                        @endif
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected((int) old('role', $account?->role_id) === $role->id)>{{ ucfirst($role->name) }}</option>
                        @endforeach
                    </x-mainstay::select>
                </x-mainstay::field>
            </x-mainstay::field-group>

            @if ($account !== null && ($account->grants !== [] || $account->denials !== []))
                <x-mainstay::field-group label="Besides the role">
                    @if ($account->grants !== [])
                        <x-mainstay::field-section label="Also granted">
                            <p class="font-mono text-xs">{{ implode(', ', $account->grants) }}</p>
                        </x-mainstay::field-section>
                    @endif
                    @if ($account->denials !== [])
                        <x-mainstay::field-section label="Denied">
                            <p class="font-mono text-xs">{{ implode(', ', $account->denials) }}</p>
                        </x-mainstay::field-section>
                    @endif
                </x-mainstay::field-group>
            @endif
        </fieldset>
    </form>

    @if ($invited && $changes)
        <form id="invite-form" method="post" action="{{ route('mainstay.users.invite', $account->id) }}">@csrf</form>
    @endif

    @if ($deletes)
        <x-mainstay::dialog id="delete-account" label="Delete this account?">
            <form method="post" action="{{ route('mainstay.users.destroy', $account->id) }}" class="space-y-4">
                @csrf
                <p>{{ $account->name }} can no longer sign in, and what they wrote reads as nobody's. This cannot be undone.</p>
                <div class="flex justify-end gap-2">
                    <x-mainstay::button type="button" variant="secondary" data-dialog-close>Keep it</x-mainstay::button>
                    <x-mainstay::button type="submit" variant="danger">Delete</x-mainstay::button>
                </div>
            </form>
        </x-mainstay::dialog>
    @endif
</x-mainstay::admin>
