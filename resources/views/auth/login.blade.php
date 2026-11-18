<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Sign in') }} · {{ __('CanIApply') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#0d1330">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="login">
<main>
    <h1><img src="{{ asset('brand/lockup-light.svg') }}" alt="{{ __('CanIApply') }}" width="213" height="32"></h1>
    <form method="post" action="{{ url('/login') }}" class="stack">
        @csrf
        <div class="field">
            <label for="email">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" @error('email') aria-describedby="email-error" aria-invalid="true" @enderror>
            @error('email')<p class="error" id="email-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
            <label for="password">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        <label class="check"><input type="checkbox" name="remember" value="1"> {{ __('Keep me signed in') }}</label>
        <button type="submit" class="btn primary">{{ __('Sign in') }}</button>
    </form>
</main>
</body>
</html>
