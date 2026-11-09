<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? __($title) : __('CanIApply') }} · {{ __('CanIApply') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
<div class="shell">
    <aside class="side">
        <a class="brand" href="{{ route('overview') }}" wire:navigate>{{ __('CanIApply') }}</a>
        <nav aria-label="{{ __('Main') }}">
            @foreach ([['overview', 'Overview'], ['jobs', 'Jobs'], ['applications', 'Applications'], ['analytics', 'Analytics'], ['sources', 'Sources'], ['settings', 'Settings']] as [$name, $label])
                <a href="{{ route($name) }}" wire:navigate @class(['current' => request()->routeIs($name) || ($name === 'jobs' && request()->routeIs('jobs.*'))]) @if (request()->routeIs($name)) aria-current="page" @endif>{{ __($label) }}</a>
            @endforeach
        </nav>
        <form method="post" action="{{ route('language') }}" class="side-foot">
            @csrf
            <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
            <button type="submit" class="link-on-ink" lang="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}</button>
        </form>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="link-on-ink">{{ __('Sign out') }}</button>
        </form>
    </aside>
    <main class="main">
        {{ $slot }}
    </main>
</div>
</body>
</html>
