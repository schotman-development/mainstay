{{-- One chosen entry in a relation's list. `$input`, `$many` and `$control`
     come from the field's component. --}}
<li data-relation-row @if ($many) draggable="true" @endif class="flex items-center gap-2 rounded-control border border-border bg-canvas px-2 py-1.5 text-sm">
    @if ($many)
        <span aria-hidden="true" class="cursor-grab text-muted">⋮⋮</span>
    @endif
    <input type="hidden" name="{{ $input }}" value="{{ $value }}">
    <span data-relation-title class="min-w-0 flex-1 truncate {{ $title === null ? 'text-muted' : '' }}">{{ $title ?? 'Missing' }}</span>
    @if ($type !== null)
        <span data-relation-type class="shrink-0 text-xs text-muted">{{ $type }}</span>
    @endif
    @if ($many)
        <button type="button" data-relation-up class="{{ $control }}">Up</button>
        <button type="button" data-relation-down class="{{ $control }}">Down</button>
    @endif
    <button type="button" data-relation-remove class="{{ $control }} hover:text-danger">Remove</button>
</li>
