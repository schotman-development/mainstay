@extends('mainstay::auth', ['title' => 'Choose a new password'])

@section('form')
    <form method="POST" action="{{ route('mainstay.password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-mainstay::field label="Email" for="email">
            <x-mainstay::input id="email" name="email" type="email" :value="old('email', $email)" autocomplete="username" required />
            @error('email')
                <p class="pt-1.5 text-xs text-danger">{{ $message }}</p>
            @enderror
        </x-mainstay::field>

        <x-mainstay::field label="New password" for="password">
            <x-mainstay::input id="password" name="password" type="password" autocomplete="new-password" required autofocus />
            @error('password')
                <p class="pt-1.5 text-xs text-danger">{{ $message }}</p>
            @enderror
        </x-mainstay::field>

        <x-mainstay::field label="New password again" for="password_confirmation">
            <x-mainstay::input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required />
        </x-mainstay::field>

        <div class="flex justify-end px-4 py-3.5">
            <x-mainstay::button type="submit">Change password</x-mainstay::button>
        </div>
    </form>
@endsection
