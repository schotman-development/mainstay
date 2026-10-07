{{-- The admin's front door. Widgets, as WordPress draws them, arrive with
     onboarding, whose checklist is the first of them. --}}
<x-mainstay::admin :breadcrumb="[['label' => 'Dashboard']]">
    <div class="h-full overflow-y-auto">
        <x-mainstay::notices class="mx-auto max-w-3xl px-6 py-8" />
    </div>
</x-mainstay::admin>
