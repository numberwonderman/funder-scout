@extends('layouts.auth', ['title' => 'Create workspace · Kindred'])
@section('content')
<div class="auth-form auth-form-register">
    <span class="eyebrow">Start with your organization</span>
    <h2>Create your workspace</h2>
    <p class="muted">Your account and research remain connected to this organization.</p>
    <form method="POST" action="{{ route('register.store') }}">@csrf
        <div class="field"><label for="name">Your name</label><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required>@error('name')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="email">Work email</label><input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>@error('email')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="organization_name">Organization name</label><input id="organization_name" name="organization_name" value="{{ old('organization_name') }}" required>@error('organization_name')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="website">Organization website</label><input id="website" type="url" name="website" value="{{ old('website') }}" placeholder="https://example.org" required>@error('website')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="grid form-two"><div class="field"><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="new-password" required>@error('password')<div class="error">{{ $message }}</div>@enderror</div><div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required></div></div>
        <button class="button wide" type="submit">Create workspace</button>
    </form>
    <p class="auth-switch">Already have a workspace? <a href="{{ route('login') }}">Sign in</a></p>
</div>
@endsection
