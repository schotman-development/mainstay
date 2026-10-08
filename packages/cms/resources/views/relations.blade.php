{{-- What a relation's search found, as the rows its list adds. --}}
@forelse ($found as $row)
    <li>
        <button type="button" data-relation-pick data-value="{{ $row['value'] }}" data-title="{{ $row['title'] }}" data-type="{{ $row['type'] }}" class="flex w-full items-baseline justify-between gap-3 rounded-control px-2 py-1.5 text-left text-sm hover:bg-surface focus-visible:outline-2 focus-visible:outline-accent">
            <span class="truncate">{{ $row['title'] }}</span>
            @if ($row['type'])
                <span class="shrink-0 text-xs text-muted">{{ $row['type'] }}</span>
            @endif
        </button>
    </li>
@empty
    @if ($query !== '')
        <li class="px-2 py-1.5 text-sm text-muted">Nothing called that.</li>
    @endif
@endforelse
