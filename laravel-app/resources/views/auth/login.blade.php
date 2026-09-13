@extends('layouts.auth', ['title' => 'Sign in · Kindred'])
@section('content')
<div class="auth-form">
    <span class="eyebrow">Welcome back</span>
    <h2>Sign in to your workspace</h2>
    <p class="muted">Continue your organization’s research and fundraising work.</p>
    <form method="POST" action="{{ route('login.store') }}">@csrf
        <div class="field"><label for="email">Work email</label><input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" autofocus required>@error('email')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required>@error('password')<div class="error">{{ $message }}</div>@enderror</div>
        <label class="check-row auth-check"><input type="checkbox" name="remember" value="1"><span><strong>Keep me signed in</strong><small>Use only on a private device.</small></span></label>
        <button class="button wide" type="submit">Sign in</button>
    </form>
    <p class="auth-switch">New to Kindred? <a href="{{ route('register') }}">Create an organization workspace</a></p>
</div>
@endsection
