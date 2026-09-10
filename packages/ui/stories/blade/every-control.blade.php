{{--
 | Every text control at once, which is the only way to see that they are one
 | control with three tags rather than three controls that look similar.
--}}
<div class="space-y-2">
    <x-mainstay::input placeholder="An input" />
    <x-mainstay::textarea rows="3" placeholder="A textarea" />
    <x-mainstay::select>
        @foreach (['Product', 'Engineering', 'Changelog'] as $option)
            <option @selected($option === 'Engineering')>{{ $option }}</option>
        @endforeach
    </x-mainstay::select>
</div>
