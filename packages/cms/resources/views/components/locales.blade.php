@props(['locale'])

@php($locales = array_keys(\Mainstay\Facades\Mainstay::locales()))

{{-- Which language the screen reads and writes, when there is more than one:
     the same screen in another, keeping everything else the query asks. --}}
@if (count($locales) > 1)
    <nav aria-label="Language" class="flex items-center rounded-control border border-border bg-surface p-0.5 text-xs">
        @foreach ($locales as $code)
            <a
                href="{{ request()->fullUrlWithQuery(['locale' => $code, 'page' => null]) }}"
                @if ($code === $locale) aria-current="true" @endif
                class="rounded-control px-2 py-0.5 uppercase {{ $code === $locale ? 'bg-canvas font-medium text-ink' : 'text-muted hover:text-ink' }} focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >{{ $code }}</a>
        @endforeach
    </nav>
@endif
