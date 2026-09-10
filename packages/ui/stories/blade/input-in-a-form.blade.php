{{-- In a form, which is what they are actually for: the label, the hint and the
     counter come from the field, and the control never knows about any of them.
     The id is stated on the field and repeated on the control, which is what
     replaces React's useId now that a slot has no scope to hand one into. --}}
<x-mainstay::field-group label="Search">
    <x-mainstay::field label="Meta title" for="meta-title">
        <x-mainstay::input id="meta-title" placeholder="Falls back to the title" />
    </x-mainstay::field>

    <x-mainstay::field label="Meta description" for="meta-description" :counter="['length' => 0, 'limit' => 160]">
        <x-mainstay::textarea id="meta-description" rows="3" />
    </x-mainstay::field>
</x-mainstay::field-group>
