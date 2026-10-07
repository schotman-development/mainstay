{{-- A path under the admin that no screen answers, drawn in the shell so the
     way back is where it always is. --}}
<x-mainstay::admin title="Not found" :breadcrumb="[['label' => 'Not found']]">
    <div class="grid h-full place-content-center px-4 text-center">
        <p class="text-sm font-medium">There is nothing here</p>
        <p class="pt-1 text-sm text-muted">The sidebar has everything there is.</p>
    </div>
</x-mainstay::admin>
