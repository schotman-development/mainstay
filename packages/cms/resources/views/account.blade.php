{{--
 | The signed-in user's own account: their name, their email, and a new
 | password, the current one asked for before either of the last two
 | changes. Their role is someone who manages accounts' to change.
--}}
<x-mainstay::admin title="Account settings" :breadcrumb="[['label' => 'Account settings']]">
    <x-slot:actions>
        <x-mainstay::button type="submit" form="settings-form">Save</x-mainstay::button>
    </x-slot:actions>

    <form id="settings-form" method="post" action="{{ route('mainstay.account.update') }}" data-dirty-form class="h-full overflow-y-auto">
        @csrf
        <div class="mx-auto max-w-3xl space-y-6 px-6 py-8">
            <x-mainstay::notices :messages="$errors->all()" />

            <x-mainstay::field-group label="Account">
                <x-mainstay::field label="Name" for="settings-name">
                    <x-mainstay::input id="settings-name" name="name" maxlength="255" required autocomplete="name" :value="old('name', $account->name)" />
                </x-mainstay::field>
                <x-mainstay::field label="Email" for="settings-email">
                    <x-mainstay::input id="settings-email" name="email" type="email" maxlength="255" required autocomplete="username" :value="old('email', $account->email)" />
                </x-mainstay::field>
                <x-mainstay::field-section label="Role">
                    <p class="text-sm">{{ ucfirst($account->role->name) }}</p>
                </x-mainstay::field-section>
            </x-mainstay::field-group>

            <x-mainstay::field-group label="Password">
                <x-mainstay::field label="New password" for="settings-password" hint="Leave empty to keep the one you have. A new one signs you out everywhere else.">
                    <x-mainstay::input id="settings-password" name="password" type="password" autocomplete="new-password" aria-describedby="settings-password-hint" />
                </x-mainstay::field>
                <x-mainstay::field label="New password again" for="settings-password-confirmation">
                    <x-mainstay::input id="settings-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" />
                </x-mainstay::field>
                <x-mainstay::field label="Current password" for="settings-current" hint="Needed to change your email or your password.">
                    <x-mainstay::input id="settings-current" name="current_password" type="password" autocomplete="current-password" aria-describedby="settings-current-hint" />
                </x-mainstay::field>
            </x-mainstay::field-group>
        </div>
    </form>
</x-mainstay::admin>
