@php
    $cell = 'px-5 py-3.5 align-middle';
@endphp

{{--
 | Every account: its name, email and role, and whether it is still to
 | choose its password from its invitation.
 |
 | ponytail: one page of every account, which holds to a few hundred; past
 | that it pages as the media grid does.
--}}
<x-mainstay::admin title="Users" :breadcrumb="[['label' => 'Users']]">
    <x-slot:actions>
        <a href="{{ route('mainstay.users.create') }}" class="inline-flex items-center rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-ink hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">New user</a>
    </x-slot:actions>

    <div class="flex h-full min-h-0 flex-col">
        <x-mainstay::notices :messages="$errors->all()" class="px-5 pt-4" />

        <div class="min-h-0 flex-1 overflow-auto">
            <table aria-label="Users" class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-muted [&>th]:sticky [&>th]:top-0 [&>th]:z-10 [&>th]:bg-canvas [&>th]:shadow-[inset_0_-1px_0_var(--color-border)]">
                        <th scope="col" class="px-5 py-3 font-medium">Name</th>
                        <th scope="col" class="px-5 py-3 font-medium">Email</th>
                        <th scope="col" class="px-5 py-3 font-medium">Role</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($users as $user)
                        <tr class="border-b border-border last:border-b-0">
                            <td class="{{ $cell }}">
                                <a href="{{ route('mainstay.users.edit', $user->id) }}" class="rounded-control font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ $user->name }}</a>
                                @if ($user->password === null)
                                    <span class="ml-2 text-xs text-muted">Invited</span>
                                @endif
                            </td>
                            <td class="{{ $cell }} text-muted">{{ $user->email }}</td>
                            <td class="{{ $cell }} whitespace-nowrap text-muted">{{ ucfirst($user->role->name) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-mainstay::admin>
