@php
    $title = $role->exists ? ucfirst($role->name) : 'New role';
    $held = old('capabilities', $role->capabilities);
    $everything = in_array('*', $held, true);
@endphp

{{--
 | A role: its name and what it holds, as a grid of what the registered
 | types derive -- a row covering every type of a shape, which holds for
 | types deployed later too, and a row per type. A box the user could not
 | hand out is disabled; read only, with no Save, for a role holding what
 | the user does not.
--}}
<x-mainstay::admin
    :title="$title"
    :breadcrumb="[['label' => 'Roles', 'href' => route('mainstay.roles')], ['label' => $title]]"
>
    <x-slot:actions>
        @if ($deletes)
            <x-mainstay::dropdown align="end" :chevron="false" trigger-class="rounded-control p-1.5 text-muted hover:bg-surface hover:text-ink">
                <x-slot:label><x-mainstay::more-icon :title="$title" /></x-slot:label>
                <x-mainstay::dropdown-item danger data-dialog-open="delete-role">Delete</x-mainstay::dropdown-item>
            </x-mainstay::dropdown>
        @endif
        @if ($changes)
            <x-mainstay::button type="submit" form="role-form">Save</x-mainstay::button>
        @endif
    </x-slot:actions>

    <form id="role-form" method="post" action="{{ $role->exists ? route('mainstay.roles.update', $role->id) : route('mainstay.roles.store') }}" data-dirty-form class="h-full overflow-y-auto">
        @csrf
        <fieldset @disabled(! $changes) class="mx-auto max-w-4xl space-y-6 px-6 py-8">
            <x-mainstay::notices :messages="$errors->all()" />

            <x-mainstay::field-group label="Role">
                <x-mainstay::field label="Name" for="role-name">
                    <x-mainstay::input id="role-name" name="name" maxlength="255" required :value="old('name', $role->name)" />
                </x-mainstay::field>
                <div class="flex items-center gap-2 px-4 py-3.5">
                    <x-mainstay::checkbox id="role-everything" name="capabilities[]" value="*" label="Everything" :checked="$everything" :disabled="! $covered('*')" />
                    <label for="role-everything" class="text-sm">Everything, every capability there is and will be</label>
                </div>
            </x-mainstay::field-group>

            @foreach ($sections as $section)
                <section class="rounded-control border border-border bg-surface">
                    <h2 class="border-b border-border px-4 py-2.5 text-xs font-medium text-muted">{{ $section['label'] }}</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-muted">
                                    <th scope="col" class="px-4 py-2 font-medium"><span class="sr-only">Type</span></th>
                                    @foreach ($section['verbs'] as $verb)
                                        <th scope="col" class="px-3 py-2 text-center font-medium">{{ $verb }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($section['rows'] as $row)
                                    <tr class="border-t border-border">
                                        <th scope="row" class="px-4 py-2 text-left font-normal">{{ $row['label'] }}</th>
                                        @foreach ($row['names'] as $i => $name)
                                            <td class="px-3 py-2 text-center">
                                                <x-mainstay::checkbox name="capabilities[]" :value="$name" :label="$row['label'].': '.$section['verbs'][$i]" :title="$name" :checked="in_array($name, $held, true)" :disabled="! $covered($name)" />
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach

            @if ($stale !== [])
                <x-mainstay::field-group label="No type derives these any longer">
                    @foreach ($stale as $name)
                        <div class="flex items-center gap-2 px-4 py-3">
                            <x-mainstay::checkbox :id="'stale-'.$name" name="capabilities[]" :value="$name" :label="$name" :checked="in_array($name, $held, true)" />
                            <label for="stale-{{ $name }}" class="font-mono text-xs">{{ $name }}</label>
                        </div>
                    @endforeach
                </x-mainstay::field-group>
            @endif
        </fieldset>
    </form>

    @if ($deletes)
        <x-mainstay::dialog id="delete-role" label="Delete this role?">
            <form method="post" action="{{ route('mainstay.roles.destroy', $role->id) }}" class="space-y-4">
                @csrf
                <p>{{ $title }} goes for good. No account holds it. This cannot be undone.</p>
                <div class="flex justify-end gap-2">
                    <x-mainstay::button type="button" variant="secondary" data-dialog-close>Keep it</x-mainstay::button>
                    <x-mainstay::button type="submit" variant="danger">Delete</x-mainstay::button>
                </div>
            </form>
        </x-mainstay::dialog>
    @endif
</x-mainstay::admin>
