@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php
    $id = 'field-'.$name;

    /* What the entry holds, or what was posted and refused, as a UTC instant;
       nothing where it is not a date at all. */
    try {
        $date = blank($value) ? null : ($value instanceof DateTimeInterface ? \Carbon\CarbonImmutable::instance($value) : \Carbon\CarbonImmutable::parse($value, 'UTC'))->utc();
    } catch (Throwable) {
        $date = null;
    }
@endphp

{{-- Native, so it brings its own picker and keyboard. A moment is drawn in
     UTC and named here; the behaviour layer shows it in the browser's zone
     and posts the offset, and takes the note about UTC away. To the second,
     so a moment code wrote at 12:30:45 posts back as itself. --}}
<x-mainstay::field :label="$field->label()" :for="$id" :hint="$hint">
    <x-mainstay::input
        :type="$field->time ? 'datetime-local' : 'date'"
        :id="$id"
        :name="$name"
        :value="$date?->format($field->time ? 'Y-m-d\TH:i:s' : 'Y-m-d')"
        :step="$field->time ? 1 : null"
        :data-utc="$field->time ? $id.'-utc' : null"
        :aria-invalid="$messages ? 'true' : null"
        :aria-describedby="$messages ? $id.'-error' : ($hint ? $id.'-hint' : null)"
        {{ $attributes }}
    />
    @if ($field->time)
        <p id="{{ $id }}-utc" class="pt-1.5 text-xs text-muted">In UTC.</p>
    @endif
    <x-mainstay::messages :id="$id.'-error'" :messages="$messages" />
</x-mainstay::field>
