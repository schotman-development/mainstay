@props(['variant' => 'primary'])

{{--
 | The caller's own classes land after these, exactly as they did in React:
 | ComponentAttributeBag::merge concatenates [defaults, caller], so a class
 | handed in from outside still loses to nothing and wins ties on sheet order.
--}}
<button {{ $attributes->class([
    'inline-flex items-center justify-center gap-2 rounded-control px-3 py-1.5',
    'text-sm font-medium transition-opacity',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
    'disabled:pointer-events-none disabled:opacity-50',
    match ($variant) {
        'secondary' => 'bg-surface text-ink border border-border hover:bg-canvas',
        'danger' => 'bg-danger text-accent-ink hover:opacity-90',
        default => 'bg-accent text-accent-ink hover:opacity-90',
    },
]) }}>{{ $slot }}</button>
