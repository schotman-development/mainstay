{{--
 | An upload: the file, and its alt text in every language, each present as
 | the library asks. Left empty, an alt marks the image decorative.
--}}
<form method="post" action="{{ route('mainstay.media.store', $pick ?? false ? ['pick' => 1] : []) }}" enctype="multipart/form-data" data-media-upload class="space-y-3">
    @csrf
    <div>
        <label for="media-file{{ $suffix ?? '' }}" class="block pb-1.5 text-xs font-medium text-muted">Image</label>
        <input id="media-file{{ $suffix ?? '' }}" type="file" name="file" accept="image/jpeg,image/png,image/gif,image/webp" required class="block w-full text-sm file:mr-3 file:rounded-control file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-sm">
    </div>
    @foreach ($locales as $code => $locale)
        <div>
            <label for="media-alt-{{ $code }}{{ $suffix ?? '' }}" class="block pb-1.5 text-xs font-medium text-muted">Alt text{{ count($locales) > 1 ? ' ('.strtoupper($code).')' : '' }}</label>
            <x-mainstay::input :id="'media-alt-'.$code.($suffix ?? '')" :name="'alt['.$code.']'" maxlength="1000" />
        </div>
    @endforeach
    <p class="text-xs text-muted">Describe what the image shows for someone who cannot see it. Leave it empty for an image that is only decoration.</p>
    <div class="flex justify-end">
        <x-mainstay::button type="submit">Upload</x-mainstay::button>
    </div>
</form>
