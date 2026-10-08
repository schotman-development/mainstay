{{--
 | The library as an image field's dialog shows it, fetched into the dialog
 | rather than drawn in the shell: the grid to choose from, its pages, and an
 | upload, which answers with this again, the new image first.
--}}
<div data-media-picker class="space-y-4">
    @foreach ($refused ?? [] as $message)
        <p role="alert" class="rounded-control border border-danger/40 bg-surface px-3 py-2 text-sm text-danger">{{ $message }}</p>
    @endforeach

    @if ($images->isEmpty())
        <p class="text-sm text-muted">No images yet.</p>
    @else
        @include('mainstay::media.grid', ['mode' => 'pick'])
    @endif

    @if ($images->lastPage() > 1)
        <div class="flex items-center justify-between text-xs text-muted">
            <span>Page {{ $images->currentPage() }} of {{ $images->lastPage() }}</span>
            <span class="flex gap-2">
                @if ($images->currentPage() > 1)
                    <a href="{{ route('mainstay.media', ['pick' => 1, 'page' => $images->currentPage() - 1]) }}" data-picker-page class="rounded-control border border-border px-2 py-0.5 text-ink hover:bg-surface">Previous</a>
                @endif
                @if ($images->hasMorePages())
                    <a href="{{ route('mainstay.media', ['pick' => 1, 'page' => $images->currentPage() + 1]) }}" data-picker-page class="rounded-control border border-border px-2 py-0.5 text-ink hover:bg-surface">Next</a>
                @endif
            </span>
        </div>
    @endif

    @if (\Mainstay\Navigation::uploads())
        <details class="border-t border-border pt-3">
            <summary class="cursor-pointer text-sm text-muted hover:text-ink">Upload a new one</summary>
            <div class="pt-3">@include('mainstay::media.upload', ['pick' => true, 'suffix' => '-pick'])</div>
        </details>
    @endif
</div>
