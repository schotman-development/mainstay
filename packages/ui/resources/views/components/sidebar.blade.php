@props(['sections', 'current' => null, 'id' => 'sidebar'])

@php($active = \Mainstay\Ui\Navigation::activeKey($sections, $current))

{{--
 | The section navigation. Items are data rather than slots so a caller states
 | the pathname once instead of working out `current` for every link.
 |
 | ponytail: no scroll of its own, because a flyout hung at left-full is outside
 | the padding box and a scroll container clips it -- overflow-y auto forces
 | overflow-x to auto with it, so the panel would be cut off at the divider. The
 | navigation scrolls with the page, as WordPress's does. If it ever grows past
 | the viewport, position the flyout fixed from the row's bounding rect and put
 | the scroll back.
--}}
<nav aria-label="Sections" {{ $attributes->class(['flex w-48 shrink-0 flex-col gap-5 border-r border-border bg-surface px-2 py-3']) }}>
    @foreach ($sections as $s => $section)
        <div>
            @if (! empty($section['label']))
                <h2 id="{{ $id }}-{{ $s }}" class="px-2 pb-2 text-xs font-medium uppercase tracking-wider text-muted">{{ $section['label'] }}</h2>
            @endif

            {{-- aria-labelledby is what ties the group name to its items;
                 without it a screen reader announces four unlabelled lists in a
                 row. --}}
            <ul @if (! empty($section['label'])) aria-labelledby="{{ $id }}-{{ $s }}" @endif class="flex flex-col gap-1">
                @foreach ($section['items'] as $i => $item)
                    <x-mainstay::sidebar-item :item="$item" :path="$s.'.'.$i" :active="$active" :owner="$id" />
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
