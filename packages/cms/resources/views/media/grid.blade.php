{{--
 | The library's images, newest first, each by its preview: a link to its
 | page, a button choosing it in the picker, or Restore and Delete for good in
 | the trash, each for whoever the library would let do it. `$mode` is which.
--}}
@php
    $preview = ['preview' => \Mainstay\Media\Library::preview()];
    $may = \Mainstay\Navigation::media(...);
@endphp

<ul class="grid grid-cols-[repeat(auto-fill,minmax(9rem,1fr))] gap-3">
    @foreach ($images as $image)
        @php($src = $image->sized($preview)->url('preview'))
        <li class="overflow-hidden rounded-control border border-border bg-canvas {{ ($picked ?? null) === $image->id ? 'ring-2 ring-accent' : '' }}">
            @if ($mode === 'pick')
                <button type="button" data-pick="{{ $image->id }}" data-src="{{ $src }}" @if (($picked ?? null) === $image->id) data-picked @endif class="block w-full text-left focus-visible:outline-2 focus-visible:outline-accent">
                    <img src="{{ $src }}" alt="" class="aspect-square w-full bg-surface object-cover">
                    <span class="block truncate px-2 py-1.5 text-xs">{{ $image->name }}</span>
                </button>
            @elseif ($mode === 'trash')
                <img src="{{ $src }}" alt="" class="aspect-square w-full bg-surface object-cover opacity-60">
                <div class="flex items-center justify-between gap-1 px-2 py-1.5">
                    <span class="truncate text-xs">{{ $image->name }}</span>
                    @if ($may('restore', $image) || $may('forceDelete', $image))
                        <x-mainstay::dropdown align="end" :chevron="false" trigger-class="rounded-control p-1 text-muted hover:bg-surface hover:text-ink">
                            <x-slot:label><x-mainstay::more-icon :title="$image->name" /></x-slot:label>
                            @if ($may('restore', $image))
                                <x-mainstay::dropdown-item type="submit" :form="'restore-'.$image->id">Restore</x-mainstay::dropdown-item>
                            @endif
                            @if ($may('forceDelete', $image))
                                <x-mainstay::dropdown-item danger :data-dialog-open="'destroy-'.$image->id">Delete for good</x-mainstay::dropdown-item>
                            @endif
                        </x-mainstay::dropdown>
                    @endif
                </div>
            @else
                <a href="{{ route('mainstay.media.edit', $image->id) }}" class="block focus-visible:outline-2 focus-visible:outline-accent">
                    <img src="{{ $src }}" alt="" class="aspect-square w-full bg-surface object-cover">
                    <span class="block truncate px-2 py-1.5 text-xs">{{ $image->name }}</span>
                </a>
            @endif
        </li>
    @endforeach
</ul>

@if ($mode === 'trash')
    {{-- Outside the grid's markup, as every form a dialog or a menu posts. --}}
    @foreach ($images as $image)
        @if ($may('restore', $image))
            <form id="restore-{{ $image->id }}" method="post" action="{{ route('mainstay.media.restore', $image->id) }}">@csrf</form>
        @endif
        @continue(! $may('forceDelete', $image))
        <x-mainstay::dialog :id="'destroy-'.$image->id" label="Delete for good?">
            <form method="post" action="{{ route('mainstay.media.destroy', $image->id) }}" class="space-y-4">
                @csrf
                <p>{{ $image->name }} leaves the library. Entries still holding it show it as missing.</p>
                <div class="flex justify-end gap-2">
                    <x-mainstay::button type="button" variant="secondary" data-dialog-close>Keep it</x-mainstay::button>
                    <x-mainstay::button type="submit" variant="danger">Delete for good</x-mainstay::button>
                </div>
            </form>
        </x-mainstay::dialog>
    @endforeach
@endif
