@props(['name', 'email' => null])

@php
    $initials = mb_strtoupper(implode('', array_map(
        fn (string $word) => mb_substr($word, 0, 1),
        array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2),
    )));
@endphp

<x-mainstay::dropdown align="end" trigger-class="h-6 px-1.5 text-xs font-medium text-ink hover:bg-canvas" {{ $attributes }}>
    <x-slot:label>
        <span aria-hidden="true" class="flex size-5 items-center justify-center rounded-full bg-accent text-[0.6875rem] font-semibold text-accent-ink">{{ $initials }}</span>
        <span class="max-w-40 truncate">{{ $name }}</span>
    </x-slot:label>

    {{-- Widens the panel past its min-w-48 and gives the truncating email
         something to truncate against. --}}
    <div class="w-56 border-b border-border px-3 pb-2 pt-1.5">
        <p class="truncate text-sm font-medium text-ink">{{ $name }}</p>
        @if ($email)
            <p class="truncate text-xs text-muted">{{ $email }}</p>
        @endif
    </div>

    {{-- The two standard items, unless the caller states its own. React took a
         callback per item; a server-rendered admin needs a link for one and a
         form for the other, so the slot is where that gets decided. --}}
    @if ($slot->isEmpty())
        <x-mainstay::dropdown-item>Account settings</x-mainstay::dropdown-item>
        <x-mainstay::dropdown-item danger>Sign out</x-mainstay::dropdown-item>
    @else
        {{ $slot }}
    @endif
</x-mainstay::dropdown>
