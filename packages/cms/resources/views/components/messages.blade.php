@props(['id', 'messages' => []])

{{-- A field's errors, named by the id its control's aria-describedby points
     at, so a screen reader reads them with the control. --}}
@if ($messages !== [])
    <div id="{{ $id }}">
        @foreach ($messages as $message)
            <p class="pt-1.5 text-xs text-danger">{{ $message }}</p>
        @endforeach
    </div>
@endif
