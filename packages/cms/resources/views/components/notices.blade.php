@props(['messages' => []])

{{-- What the last request said: the status it left, and anything it refused
     that no field on the screen is there to show. --}}
@if (session('status') || $messages !== [])
    <div {{ $attributes->class('space-y-2') }}>
        @if (session('status'))
            <p role="status" class="rounded-control border border-border bg-surface px-3 py-2 text-sm">{{ session('status') }}</p>
        @endif

        @foreach ($messages as $message)
            <p role="alert" class="rounded-control border border-danger/40 bg-surface px-3 py-2 text-sm text-danger">{{ $message }}</p>
        @endforeach
    </div>
@endif
