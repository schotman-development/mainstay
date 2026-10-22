{{--
 | The link dialog every editor on the page shares, the rich text fields' and
 | the Content tab's, drawn once whichever asks first.
--}}
@once
    @push('dialogs')
        {{-- Outside the entry's form so its own can submit: whichever editor
             opened it answers it. --}}
        <x-mainstay::dialog id="mainstay-link" label="Link">
            <form method="dialog" class="space-y-4">
                <div>
                    <label for="mainstay-link-href" class="block pb-1.5 text-xs font-medium text-muted">Address</label>
                    <x-mainstay::input id="mainstay-link-href" name="href" autocomplete="off" autofocus placeholder="https://, mailto:, tel: or /a-path" aria-describedby="mainstay-link-error" />
                    <p id="mainstay-link-error" data-link-error hidden class="pt-1.5 text-xs text-danger">A link leads to a web, mail or phone address, or a path on this site.</p>
                </div>
                {{-- Apply first, since Enter in the address submits with the
                     form's first submit button; drawn last, where it reads. --}}
                <div class="flex flex-row-reverse gap-2">
                    <x-mainstay::button type="submit" value="apply">Apply</x-mainstay::button>
                    <x-mainstay::button type="button" variant="secondary" data-dialog-close>Cancel</x-mainstay::button>
                    <x-mainstay::button type="submit" variant="secondary" value="remove" formnovalidate class="mr-auto">Remove link</x-mainstay::button>
                </div>
            </form>
        </x-mainstay::dialog>
    @endpush
@endonce
