@extends('mainstay::auth', ['title' => 'Sign in'])

@section('form')
    @if ($empty)
        <p class="border-b border-border px-4 py-3.5 text-sm">
            There is no account yet. Make the first on the server with
            <code class="font-mono text-xs">php artisan mainstay:user</code>.
        </p>
    @endif

    <form method="POST" action="{{ route('mainstay.login') }}">
        @csrf

        <x-mainstay::field label="Email" for="email">
            <x-mainstay::input id="email" name="email" type="email" :value="old('email')" autocomplete="username" required autofocus
                :aria-invalid="$errors->has('email') ? 'true' : null" :aria-describedby="$errors->has('email') ? 'email-error' : null" />
            @error('email')
                <p id="email-error" class="pt-1.5 text-xs text-danger">{{ $message }}</p>
            @enderror
        </x-mainstay::field>

        <x-mainstay::field label="Password" for="password">
            <x-mainstay::input id="password" name="password" type="password" autocomplete="current-password" required />
        </x-mainstay::field>

        <div class="flex items-center justify-between gap-3 px-4 py-3.5">
            <label class="flex items-center gap-2 text-sm">
                <x-mainstay::checkbox name="remember" value="1" label="Remember me" />
                Remember me
            </label>

            <x-mainstay::button type="submit">Sign in</x-mainstay::button>
        </div>
    </form>
@endsection

@section('after')
    <a href="{{ route('mainstay.password.request') }}" class="text-sm text-muted hover:text-ink">Forgot your password?</a>
@endsection
