@props(['css'])

@php
    /*
     | The tokens are read out of theme.css rather than listed here, so this page
     | cannot drift from the source of truth. Reading them back off the document
     | instead would be incomplete: Tailwind only emits the theme variables a
     | build actually uses, so an unreferenced token would silently vanish.
     |
     | The story hands the stylesheet over as an arg. __DIR__ inside a Blade
     | template is the compiled view's directory rather than the source's, so
     | reading the file from here would point at storage/framework/views.
     */
    preg_match_all('/^\s*--(([a-z]+)[a-z0-9-]*):\s*([^;]+);/im', $css, $matches, PREG_SET_ORDER);

    $tokens = array_map(fn ($match) => [
        'name' => '--'.$match[1],
        'namespace' => $match[2],
        'value' => trim($match[3]),
    ], $matches);

    $namespaces = array_values(array_unique(array_column($tokens, 'namespace')));
@endphp

<div class="flex flex-col gap-8">
    @foreach ($namespaces as $namespace)
        <section class="flex flex-col gap-2">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $namespace }}</h2>

            <div class="divide-y divide-border overflow-hidden rounded-control border border-border bg-surface">
                @foreach (array_filter($tokens, fn ($token) => $token['namespace'] === $namespace) as $token)
                    @php($swatch = "var({$token['name']})")

                    <div class="flex items-center gap-4 p-3">
                        @switch ($namespace)
                            @case ('color')
                                <div class="size-12 shrink-0 rounded-control border border-border" style="background: {{ $swatch }}"></div>
                                @break
                            @case ('radius')
                                <div class="size-12 shrink-0 border-2 border-accent" style="border-radius: {{ $swatch }}"></div>
                                @break
                            @case ('font')
                                <div class="flex size-12 shrink-0 items-center justify-center text-lg" style="font-family: {{ $swatch }}">Ag</div>
                                @break
                            @default
                                <div class="size-12 shrink-0"></div>
                        @endswitch

                        <div class="min-w-0">
                            <code class="font-mono text-sm text-ink">{{ $token['name'] }}</code>
                            <p class="truncate font-mono text-xs text-muted">{{ $token['value'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
