<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f1e8">
    <title>{{ $title ?? 'Kindred' }}</title>
    <link rel="stylesheet" href="{{ asset('css/kindred.css') }}">
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
</head>
<body class="auth-page">
<main class="auth-shell">
    <section class="auth-brand">
        <img src="{{ asset('images/kindred-logo.png') }}" alt="Kindred — People fuel possibility">
        <div><span class="eyebrow">Institutional funder research</span><h1>Evidence-led fundraising intelligence for your organization.</h1><p class="lede">Find relevant funders, understand the evidence, and decide what to pursue—all in one private workspace.</p></div>
        <p class="auth-proof">Structured research · Verified sources · Deterministic fit</p>
    </section>
    <section class="auth-form-wrap">
        @yield('content')
    </section>
</main>
</body>
</html>
