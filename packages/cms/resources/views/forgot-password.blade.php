@extends('mainstay::auth', ['title' => 'Forgot your password?'])

@section('form')
    <form method="POST" action="{{ route('mainstay.password.email') }}">
        @csrf

        <x-mainstay::field label="Email" for="email" hint="A link to choose a new password is mailed to the account's email.">
            <x-mainstay::input id="email" name="email" type="email" :value="old('email')" autocomplete="username" required autofocus aria-describedby="email-hint" />
            @error('email')
                <p class="pt-1.5 text-xs text-danger">{{ $message }}</p>
            @enderror
        </x-mainstay::field>

        <div class="flex justify-end px-4 py-3.5">
            <x-mainstay::button type="submit">Mail me a link</x-mainstay::button>
        </div>
    </form>
@endsection

@section('after')
    <a href="{{ route('mainstay.login') }}" class="text-sm text-muted hover:text-ink">Back to sign in</a>
@endsection
