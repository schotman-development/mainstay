@props(['id', 'label', 'wide' => false])

{{--
 | A question asked over the page, on the platform's own <dialog>: shown modal
 | by a control naming it in `data-dialog-open`, closed by its button, Escape
 | and anything marked `data-dialog-close`. Focus is trapped and returned by
 | the browser, which is the part a hand-built modal gets wrong.
 |
 | Drawn outside any other form: a form inside a form is dropped by the parser
 | and its inputs join the outer one.
--}}
<dialog
    id="{{ $id }}"
    aria-labelledby="{{ $id }}-label"
    {{ $attributes->class(($wide ? 'max-w-3xl' : 'max-w-md').' m-auto w-full rounded-control border border-border bg-canvas p-0 text-ink shadow-lg backdrop:bg-ink/30') }}
>
    <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
        <h2 id="{{ $id }}-label" class="text-sm font-medium">{{ $label }}</h2>

        <button type="button" data-dialog-close aria-label="Close" class="rounded-control p-1 text-muted hover:bg-surface hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
            <x-mainstay::icon><path d="m4 4 8 8M12 4l-8 8" /></x-mainstay::icon>
        </button>
    </div>

    <div class="px-4 py-4 text-sm">{{ $slot }}</div>
</dialog>
