@php
    $cell = 'px-5 py-3.5 align-middle';
@endphp

{{-- Every role, and how many accounts hold it. --}}
<x-mainstay::admin title="Roles" :breadcrumb="[['label' => 'Roles']]">
    <x-slot:actions>
        <a href="{{ route('mainstay.roles.create') }}" class="inline-flex items-center rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-ink hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">New role</a>
    </x-slot:actions>

    <div class="flex h-full min-h-0 flex-col">
        <x-mainstay::notices :messages="$errors->all()" class="px-5 pt-4" />

        <div class="min-h-0 flex-1 overflow-auto">
            <table aria-label="Roles" class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-muted [&>th]:sticky [&>th]:top-0 [&>th]:z-10 [&>th]:bg-canvas [&>th]:shadow-[inset_0_-1px_0_var(--color-border)]">
                        <th scope="col" class="px-5 py-3 font-medium">Name</th>
                        <th scope="col" class="px-5 py-3 font-medium">Accounts</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($roles as $role)
                        <tr class="border-b border-border last:border-b-0">
                            <td class="{{ $cell }}">
                                <a href="{{ route('mainstay.roles.edit', $role->id) }}" class="rounded-control font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ ucfirst($role->name) }}</a>
                            </td>
                            <td class="{{ $cell }} tabular-nums text-muted">{{ $holding[$role->id] ?? 0 }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-mainstay::admin>
